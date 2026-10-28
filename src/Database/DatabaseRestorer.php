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

    public function __construct(
        protected Connection $db
    ) {
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
            $this->db->unprepared($stmt);
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
            $this->db->unprepared($stmt);
            $this->statementsRun++;
            return 1;
        }
        return 0;
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
