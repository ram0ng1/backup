<?php

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
