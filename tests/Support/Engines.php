<?php

namespace Ramon\Backup\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Events\Dispatcher;
use PDO;

/**
 * Builds standalone Eloquent (Capsule) connections to each database
 * engine the extension claims to support, so the transfer tests can run
 * the real `DatabaseDumper`/`DatabaseRestorer` against live servers.
 *
 * Connection coordinates come from environment variables — one per
 * engine — formatted as a `key=value;key=value` string:
 *
 *   BACKUP_TEST_MYSQL=host=127.0.0.1;port=3306;username=root;password=;database=backup_xfer_test
 *
 * An engine whose variable is unset (or whose server is unreachable) is
 * reported as unavailable and its test pairs are skipped, so the suite
 * still runs on a bare checkout — SQLite needs no server and is always
 * available when the pdo_sqlite extension is loaded.
 *
 * Every engine talks to a DEDICATED `backup_xfer_test` database that the
 * harness creates on demand. This keeps the dump scoped to the fixture
 * tables and guarantees we never read from or clobber a real Flarum
 * install that happens to live on the same server.
 */
final class Engines
{
    /** Engine keys, matching the Dialect enum values. */
    public const ALL = ['mysql', 'mariadb', 'postgres', 'sqlite'];

    private const DB_NAME = 'backup_xfer_test';

    /** @var array<string, Connection|false> dialect => connection, or false when unavailable */
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

    /** @return list<string> dialects reachable in this environment */
    public static function available(): array
    {
        return array_values(array_filter(self::ALL, fn ($d) => self::connection($d) !== null));
    }

    /**
     * Boot Eloquent on the shared manager so models (e.g. the Backup
     * row ExportJob writes in finalize) resolve through these test
     * connections. Idempotent.
     */
    public static function bootEloquent(): void
    {
        self::capsule()->setAsGlobal();
        self::capsule()->bootEloquent();
    }

    /** Point Eloquent's default connection at a given engine. */
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
        $config = match ($dialect) {
            'sqlite'   => self::sqliteConfig(),
            'mysql'    => self::serverConfig('mysql', 'mysql', 3306),
            'mariadb'  => self::serverConfig('mariadb', 'mariadb', 3306),
            'postgres' => self::serverConfig('postgres', 'pgsql', 5432),
            default    => throw new \InvalidArgumentException("Unknown engine: $dialect"),
        };

        $capsule = self::capsule();
        // Re-add each call is cheap and lets us reuse the same manager
        // across the suite without leaking state between named conns.
        $capsule->addConnection($config, $dialect);

        return $capsule->getConnection($dialect);
    }

    private static function sqliteConfig(): array
    {
        if (self::$sqliteFile === null) {
            $file = tempnam(sys_get_temp_dir(), 'bkp_sqlite_') ?: throw new \RuntimeException('tempnam failed');
            // tempnam creates an empty file; a 0-byte file is a valid
            // (empty) SQLite database, so nothing else to do.
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
     * Build a server connection config from the engine's env var,
     * ensuring the dedicated test database exists first. The `driver`
     * is intentionally distinct for mariadb so the connection reports
     * driverName `mariadb` (illuminate v13's dedicated driver) and
     * exercises the dialect-detection path that regressed.
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
        // Guard the database name to a strict identifier so the
        // unparametrisable CREATE DATABASE can never carry injection.
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
