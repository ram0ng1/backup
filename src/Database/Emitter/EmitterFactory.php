<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
