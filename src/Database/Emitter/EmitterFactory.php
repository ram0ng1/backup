<?php

namespace Ramon\Backup\Database\Emitter;

use Ramon\Backup\Database\Dialect;

class EmitterFactory
{
    public static function for(Dialect $target): SqlEmitter
    {
        return match ($target) {
            Dialect::MYSQL    => new MysqlEmitter(Dialect::MYSQL),
            Dialect::MARIADB  => new MysqlEmitter(Dialect::MARIADB),
            Dialect::POSTGRES => new PostgresEmitter(),
            Dialect::SQLITE   => new SqliteEmitter(),
        };
    }
}
