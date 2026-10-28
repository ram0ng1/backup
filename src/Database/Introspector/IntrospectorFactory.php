<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Database\Introspector;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\Dialect;

class IntrospectorFactory
{
    public static function for(Connection $db, Dialect $source): SchemaIntrospector
    {
        return match ($source) {
            Dialect::MYSQL,
            Dialect::MARIADB  => new MysqlIntrospector($db),
            Dialect::POSTGRES => new PostgresIntrospector($db),
            Dialect::SQLITE   => new SqliteIntrospector($db),
        };
    }
}
