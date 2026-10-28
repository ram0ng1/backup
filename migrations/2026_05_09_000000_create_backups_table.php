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

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('backups')) {
            return;
        }

        $schema->create('backups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('filename', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('encrypted')->default(false);
            // O que foi incluído, separado por vírgula: "db,assets,storage,extensions".
            $table->string('contents', 64)->default('');
            $table->string('flarum_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['created_at']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('backups');
    },
];
