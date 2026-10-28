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
 * Records which database engine each backup was generated FOR. NULL
 * means "same as source" (a regular backup of this install). A value
 * like "postgres" means the SQL dump was translated for that target,
 * so the admin sees a clear flag in the list and isn't surprised to
 * find their MySQL backup file is full of PG-flavoured DDL.
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
