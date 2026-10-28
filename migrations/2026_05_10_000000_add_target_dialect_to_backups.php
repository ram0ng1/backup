<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Banco para o qual o dump foi gerado. NULL é o mesmo da origem; "postgres",
 * por exemplo, indica um dump traduzido, sinalizado na lista de backups.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('backups')) return;
        if ($schema->hasColumn('backups', 'target_dialect')) return;

        $schema->table('backups', function (Blueprint $table) {
            $table->string('target_dialect', 16)->nullable()->after('php_version');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('backups')) return;
        if (! $schema->hasColumn('backups', 'target_dialect')) return;

        $schema->table('backups', function (Blueprint $table) {
            $table->dropColumn('target_dialect');
        });
    },
];
