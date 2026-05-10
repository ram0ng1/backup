<?php

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * The set of database engines this extension can read from AND write
 * to. The four are kept distinct (rather than collapsing MariaDB into
 * MySQL) so we can target version-specific syntax — e.g. MariaDB
 * tolerates `JSON` as a synonym for LONGTEXT but predates MySQL's
 * native JSON checks; SQLite cannot ALTER TABLE to add FK; PostgreSQL
 * needs explicit sequence resets after a bulk load.
 */
enum Dialect: string
{
    case MYSQL    = 'mysql';
    case MARIADB  = 'mariadb';
    case POSTGRES = 'postgres';
    case SQLITE   = 'sqlite';

    /** Detect the dialect of a live Connection (used to pick the introspector). */
    public static function detect(Connection $db): self
    {
        $driver = $db->getDriverName();
        return match ($driver) {
            'mysql'  => self::isMariaDb($db) ? self::MARIADB : self::MYSQL,
            'pgsql'  => self::POSTGRES,
            'sqlite' => self::SQLITE,
            default  => throw new RuntimeException("Unsupported database driver: $driver"),
        };
    }

    /**
     * Parse a user-facing identifier (case-insensitive, with friendly
     * aliases) into the enum. Used by the export controller when the
     * admin picks a target.
     */
    public static function parse(string $value): self
    {
        $key = strtolower(trim($value));
        return match ($key) {
            'mysql'                                  => self::MYSQL,
            'mariadb'                                => self::MARIADB,
            'postgres', 'postgresql', 'pgsql', 'pg'  => self::POSTGRES,
            'sqlite', 'sqlite3'                      => self::SQLITE,
            default => throw new RuntimeException("Unknown target dialect: $value"),
        };
    }

    /** True when this dialect uses backticks for identifiers (MySQL/MariaDB). */
    public function usesBackticks(): bool
    {
        return $this === self::MYSQL || $this === self::MARIADB;
    }

    /** True for the MySQL family — many quirks (FK toggle, charset, JSON) align. */
    public function isMysqlFamily(): bool
    {
        return $this === self::MYSQL || $this === self::MARIADB;
    }

    private static function isMariaDb(Connection $db): bool
    {
        try {
            $row = $db->selectOne('SELECT VERSION() AS v');
            $v = is_object($row) ? ($row->v ?? '') : (is_array($row) ? ($row['v'] ?? '') : '');
            return is_string($v) && stripos($v, 'mariadb') !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
