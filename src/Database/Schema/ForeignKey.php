<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Database\Schema;

class ForeignKey
{
    /**
     * @param list<string> $columns
     * @param list<string> $refColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $refTable,
        public readonly array $refColumns,
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
    ) {
    }
}
