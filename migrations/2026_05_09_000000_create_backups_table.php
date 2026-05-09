<?php

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
            // Comma-separated list of what was bundled: "db,assets,storage,extensions".
            // Keeps reporting cheap without joining a sub-table.
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
