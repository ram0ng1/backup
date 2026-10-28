<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule; /* harness de teste standalone, sem boot do Flarum; nosemgrep: flarum-v2-capsule-manager */
use Illuminate\Database\Connection;
use Illuminate\Events\Dispatcher;
use PDO;

/**
 * Conexões Eloquent (Capsule) avulsas para cada motor suportado, para os
 * testes de transferência rodarem o `DatabaseDumper`/`DatabaseRestorer`
 * reais contra servidores no ar.
 *
 * Cada motor lê uma variável de ambiente no formato `chave=valor;...`:
 *
 *   BACKUP_TEST_MYSQL=host=127.0.0.1;port=3306;username=root;password=;database=backup_xfer_test
 *
 * Motor sem variável (ou fora do ar) é pulado; o SQLite não precisa de
 * servidor e roda sempre que o pdo_sqlite estiver carregado. Todos usam
 * um banco DEDICADO, `backup_xfer_test`, para o dump nunca ler nem
 * apagar um Flarum real no mesmo servidor.
 */
final class Engines
{
    /** Chaves dos motores, iguais aos valores do enum Dialect. */
    public const ALL = ['mysql', 'mariadb', 'postgres', 'sqlite'];

    private const DB_NAME = 'backup_xfer_test';

    /** @var array<string, Connection|false> dialeto => conexão, ou false se indisponível */
    private static array $cache = [];

    private static ?Capsule $capsule = null;

    private static ?string $sqliteFile = null;

    public static function connection(string $dialect): ?Connection
    {
        if (array_key_exists($dialect, self::$cache)) {
            return self::$cache[$dialect] ?: null;
        }

        try {
            $conn = self::make($dialect);
            $conn->select('SELECT 1');
            self::$cache[$dialect] = $conn;
            return $conn;
        } catch (\Throwable $e) {
            self::$cache[$dialect] = false;
            return null;
        }
    }

    /** @return list<string> dialetos acessíveis neste ambiente */
    public static function available(): array
    {
        return array_values(array_filter(self::ALL, fn ($d) => self::connection($d) !== null));
    }

    /**
     * Liga o Eloquent ao manager compartilhado, para os models (ex.: o Backup
     * que o ExportJob grava no finalize) usarem estas conexões. Idempotente.
     */
    public static function bootEloquent(): void
    {
        self::capsule()->setAsGlobal();
        self::capsule()->bootEloquent();
    }

    /**
     * Conexão nova a cada chamada, como cada tick HTTP do import recebe.
     * Estado de sessão (`SET ...`) não passa de uma para outra.
     */
    public static function freshConnection(string $dialect): ?Connection
    {
        if (self::connection($dialect) === null) {
            return null;
        }

        $name = $dialect.'_fresh_'.bin2hex(random_bytes(4));
        self::capsule()->addConnection(self::configFor($dialect), $name);

        return self::capsule()->getConnection($name);
    }

    /** Aponta a conexão padrão do Eloquent para o motor dado. */
    public static function setDefaultConnection(string $dialect): void
    {
        self::capsule()->getDatabaseManager()->setDefaultConnection($dialect);
    }

    private static function capsule(): Capsule
    {
        if (self::$capsule === null) {
            $c = new Capsule();
            $c->setEventDispatcher(new Dispatcher(new Container()));
            $c->setAsGlobal();
            self::$capsule = $c;
        }
        return self::$capsule;
    }

    private static function make(string $dialect): Connection
    {
        $capsule = self::capsule();
        // Registrar de novo a cada chamada é barato e evita vazar estado entre
        // conexões nomeadas ao longo da suíte.
        $capsule->addConnection(self::configFor($dialect), $dialect);

        return $capsule->getConnection($dialect);
    }

    /** @return array<string, mixed> */
    private static function configFor(string $dialect): array
    {
        return match ($dialect) {
            'sqlite'   => self::sqliteConfig(),
            'mysql'    => self::serverConfig('mysql', 'mysql', 3306),
            'mariadb'  => self::serverConfig('mariadb', 'mariadb', 3306),
            'postgres' => self::serverConfig('postgres', 'pgsql', 5432),
            default    => throw new \InvalidArgumentException("Unknown engine: $dialect"),
        };
    }

    private static function sqliteConfig(): array
    {
        if (self::$sqliteFile === null) {
            $file = tempnam(sys_get_temp_dir(), 'bkp_sqlite_') ?: throw new \RuntimeException('tempnam failed');
            // Arquivo vazio já é um banco SQLite válido.
            self::$sqliteFile = $file;
        }

        return [
            'driver'                  => 'sqlite',
            'database'                => self::$sqliteFile,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
    }

    /**
     * Monta a configuração a partir da variável do motor, criando antes o
     * banco de teste. O `driver` do mariadb é separado de propósito: a conexão
     * se apresenta como `mariadb` (driver próprio do illuminate v13) e passa
     * pelo caminho de detecção que já regrediu.
     */
    private static function serverConfig(string $engine, string $driver, int $defaultPort): array
    {
        $raw = getenv('BACKUP_TEST_' . strtoupper($engine));
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException("Engine $engine not configured");
        }

        $parts = [];
        foreach (explode(';', $raw) as $pair) {
            if (! str_contains($pair, '=')) continue;
            [$k, $v] = explode('=', $pair, 2);
            $parts[trim($k)] = trim($v);
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int) ($parts['port'] ?? $defaultPort);
        $user = $parts['username'] ?? ($parts['user'] ?? 'root');
        $pass = $parts['password'] ?? ($parts['pass'] ?? '');
        $db   = $parts['database'] ?? ($parts['db'] ?? self::DB_NAME);

        self::ensureDatabase($driver, $host, $port, $user, $pass, $db);

        $config = [
            'driver'   => $driver,
            'host'     => $host,
            'port'     => $port,
            'database' => $db,
            'username' => $user,
            'password' => $pass,
            'prefix'   => '',
        ];

        if ($driver === 'pgsql') {
            $config['charset'] = 'utf8';
            $config['schema']  = 'public';
        } else {
            $config['charset']   = 'utf8mb4';
            $config['collation'] = 'utf8mb4_unicode_ci';
        }

        return $config;
    }

    private static function ensureDatabase(string $driver, string $host, int $port, string $user, string $pass, string $db): void
    {
        // Nome do banco restrito a identificador simples: o CREATE DATABASE não
        // aceita binding.
        if (! preg_match('/^[A-Za-z0-9_]+$/', $db)) {
            throw new \RuntimeException("Unsafe test database name: $db");
        }

        if ($driver === 'pgsql') {
            $pdo = new PDO("pgsql:host=$host;port=$port;dbname=postgres", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $exists = $pdo->query('SELECT 1 FROM pg_database WHERE datname = ' . $pdo->quote($db))->fetchColumn();
            if (! $exists) {
                $pdo->exec("CREATE DATABASE \"$db\" ENCODING 'UTF8'");
            }
            return;
        }

        // mysql / mariadb
        $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
