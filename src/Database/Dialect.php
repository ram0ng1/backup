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
use RuntimeException;

/**
 * Engines que a extensão lê e escreve. MariaDB fica separado do MySQL
 * porque a sintaxe difere em pontos que importam (JSON, por exemplo); o
 * SQLite não adiciona FK por ALTER TABLE e o PostgreSQL precisa
 * reajustar sequências depois da carga.
 */
enum Dialect: string
{
    case MYSQL    = 'mysql';
    case MARIADB  = 'mariadb';
    case POSTGRES = 'postgres';
    case SQLITE   = 'sqlite';

    /** Dialeto de uma conexão viva (escolhe o introspector). */
    public static function detect(Connection $db): self
    {
        $driver = $db->getDriverName();
        return match ($driver) {
            'mysql'   => self::isMariaDb($db) ? self::MARIADB : self::MYSQL,
            'mariadb' => self::MARIADB,
            'pgsql'   => self::POSTGRES,
            'sqlite'  => self::SQLITE,
            default   => throw new RuntimeException("Unsupported database driver: $driver"),
        };
    }

    /** Aceita o identificador do admin sem diferenciar maiúsculas e com apelidos. */
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

    public function usesBackticks(): bool
    {
        return $this === self::MYSQL || $this === self::MARIADB;
    }

    /** Família MySQL: toggle de FK, charset e JSON se comportam igual. */
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
