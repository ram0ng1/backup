<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Executa o SQL do `DatabaseDumper`. O chamador alimenta bytes com
 * `feed()` e `executeReady()` roda os statements completos; o que vem
 * depois do último `STATEMENT_DELIMITER` fica no buffer.
 *
 * A instância NÃO sobrevive ao tick: cada tick começa com buffer vazio.
 * Logo, o cursor persistido pelo chamador nunca pode passar do último byte
 * executado; ou o tick termina com {@see pendingBytes()} zerado, ou o
 * cursor recua essa quantidade. Senão o tick seguinte reabre o dump no
 * meio de um literal e o banco recebe o rabo de um INSERT.
 *
 * Como cada tick abre uma sessão PDO nova, `disableForeignKeys()` e
 * `enableForeignKeys()` ficam expostos para o job aplicar a cada tick.
 */
class DatabaseRestorer
{
    private string $buffer = '';

    private int $statementsRun = 0;

    /**
     * Tabelas de que o Flarum precisa para iniciar e autenticar uma
     * requisição. Pela web cada tick é uma requisição nova: se elas fossem
     * derrubadas no primeiro tick, o seguinte nem iniciava (sem `settings`)
     * ou perdia o admin (sem o token dele em `access_tokens`). Por isso os
     * comandos delas ficam guardados e rodam juntos no fim.
     */
    public const BOOT_TABLES = ['settings', 'users', 'access_tokens', 'api_keys', 'groups', 'group_user', 'group_permission'];

    /** @var resource|null */
    private $deferredFh = null;

    /** @var list<string> */
    private array $deferredTables = [];

    public function __construct(
        protected Connection $db
    ) {
    }

    /**
     * Guarda em `$fh` os comandos das tabelas de boot em vez de executá-los.
     *
     * @param resource $fh
     */
    public function deferBootTables($fh, string $prefix = ''): void
    {
        $this->deferredFh = $fh;
        $this->deferredTables = array_map(fn (string $t) => $prefix.$t, self::BOOT_TABLES);
    }

    public function feed(string $sqlChunk): void
    {
        $this->buffer .= $sqlChunk;
    }

    /**
     * Devolve quantos statements rodaram; o resto fica no buffer.
     */
    public function executeReady(): int
    {
        $delim = DatabaseDumper::STATEMENT_DELIMITER;
        $lastIdx = strrpos($this->buffer, $delim);
        if ($lastIdx === false) {
            return 0;
        }

        $ready = substr($this->buffer, 0, $lastIdx + strlen($delim));
        $this->buffer = substr($this->buffer, $lastIdx + strlen($delim));

        $statements = explode($delim, $ready);
        $count = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $this->run($stmt);
            $count++;
        }

        $this->statementsRun += $count;
        return $count;
    }

    /**
     * Só no fim do stream. O dumper sempre termina com delimitador, mas dumps
     * antigos ou de outras ferramentas podem não terminar.
     */
    public function finish(): int
    {
        if (trim($this->buffer) === '') {
            $this->buffer = '';
            return 0;
        }
        $stmt = trim($this->buffer);
        $this->buffer = '';
        if ($stmt !== '' && ! str_starts_with($stmt, '--')) {
            $this->run($stmt);
            $this->statementsRun++;
            return 1;
        }
        return 0;
    }

    private function run(string $stmt): void
    {
        if ($this->deferredFh !== null) {
            $stmt = $this->deferIfNeeded($stmt);
            if ($stmt === null) {
                return;
            }
        }

        $this->db->unprepared($stmt);
    }

    /**
     * Devolve o que roda agora, ou null quando o comando inteiro foi guardado.
     *
     * No MySQL/MariaDB a FK vem dentro do CREATE TABLE da tabela filha, e o
     * servidor recusa criar a FK contra a tabela pai antiga quando o tipo da
     * coluna difere (restore entre motores), mesmo com FOREIGN_KEY_CHECKS=0.
     * Essas FKs saem do CREATE e voltam como ALTER TABLE junto com as tabelas
     * guardadas. No PostgreSQL elas já são ALTER TABLE separados.
     */
    private function deferIfNeeded(string $stmt): ?string
    {
        $table = self::statementTable($stmt);
        $deferred = $table !== null && in_array($table, $this->deferredTables, true);

        // `SELECT setval(pg_get_serial_sequence('users', 'id'), ...)` do PostgreSQL.
        if (! $deferred && preg_match("/pg_get_serial_sequence\\('([^']+)'/i", $stmt, $m)) {
            $deferred = in_array($m[1], $this->deferredTables, true);
        }

        if (! $deferred && $table !== null && preg_match('/\\AALTER TABLE/i', $stmt)) {
            foreach ($this->deferredTables as $t) {
                if (preg_match('/REFERENCES\\s+[`"]?'.preg_quote($t, '/').'[`"]?\\s*\\(/i', $stmt)) {
                    $deferred = true;
                    break;
                }
            }
        }

        if ($deferred) {
            $this->writeDeferred($stmt);
            return null;
        }

        if ($table !== null && preg_match('/\\ACREATE TABLE/i', $stmt)) {
            foreach ($this->deferredTables as $t) {
                $fk = '/,\\s*(CONSTRAINT\\s+`[^`]+`\\s+FOREIGN KEY\\s*\\([^)]*\\)\\s*REFERENCES\\s+`'.preg_quote($t, '/')
                    .'`\\s*\\([^)]*\\)(?:\\s+ON\\s+(?:DELETE|UPDATE)\\s+(?:CASCADE|SET NULL|SET DEFAULT|RESTRICT|NO ACTION))*)/i';
                $stmt = (string) preg_replace_callback($fk, function (array $m) use ($table) {
                    $this->writeDeferred('ALTER TABLE `'.$table.'` ADD '.$m[1]);
                    return '';
                }, $stmt);
            }
        }

        return $stmt;
    }

    private function writeDeferred(string $stmt): void
    {
        if (fwrite($this->deferredFh, $stmt.DatabaseDumper::STATEMENT_DELIMITER) === false) {
            throw new \RuntimeException('Could not write the deferred statements.');
        }
    }

    /** Tabela que o comando cria, apaga, altera ou preenche. */
    public static function statementTable(string $stmt): ?string
    {
        $pattern = '/\\A(?:DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO|ALTER TABLE(?: ONLY)?'
            .'|CREATE (?:UNIQUE )?INDEX\\s+(?:`[^`]+`|"[^"]+"|\\S+)\\s+ON)\\s+[`"]?([^`"\\s(]+)[`"]?/i';

        return preg_match($pattern, $stmt, $m) ? $m[1] : null;
    }

    public function statementsRun(): int
    {
        return $this->statementsRun;
    }

    /**
     * Bytes da cauda depois do último delimitador. Zero quer dizer fronteira
     * de statement, a única posição em que o chamador pode salvar o cursor.
     */
    public function pendingBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Estado de sessão do preâmbulo, reaplicado a cada tick: pela web cada
     * tick abre uma conexão nova e o preâmbulo só roda no primeiro. Sem
     * `standard_conforming_strings = on` uma barra invertida num post fecha o
     * literal no PostgreSQL; sem `NO_AUTO_VALUE_ON_ZERO` um id 0 vira o
     * próximo auto-increment. Se falhar, o restore para.
     */
    public static function prepareSession(Connection $db): void
    {
        match (Dialect::detect($db)) {
            Dialect::MYSQL,
            Dialect::MARIADB  => $db->unprepared("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'"),
            Dialect::POSTGRES => $db->unprepared('SET standard_conforming_strings = on'),
            Dialect::SQLITE   => null,
        };

        self::disableForeignKeys($db);
    }

    /**
     * Suspende as FKs nesta conexão, sem garantia. No PostgreSQL é no-op:
     * `session_replication_role` exige superuser, indisponível em hosts
     * gerenciados, e o dump já cria as FKs depois dos dados.
     */
    public static function disableForeignKeys(Connection $db): void
    {
        try {
            match (Dialect::detect($db)) {
                Dialect::MYSQL,
                Dialect::MARIADB  => $db->unprepared('SET FOREIGN_KEY_CHECKS = 0'),
                Dialect::SQLITE   => $db->unprepared('PRAGMA foreign_keys = OFF'),
                Dialect::POSTGRES => null, // ver docblock
            };
        } catch (Throwable) { /* best-effort */ }
    }

    public static function enableForeignKeys(Connection $db): void
    {
        try {
            match (Dialect::detect($db)) {
                Dialect::MYSQL,
                Dialect::MARIADB  => $db->unprepared('SET FOREIGN_KEY_CHECKS = 1'),
                Dialect::SQLITE   => $db->unprepared('PRAGMA foreign_keys = ON'),
                Dialect::POSTGRES => null, // ver disableForeignKeys
            };
        } catch (Throwable) { /* best-effort */ }
    }
}
