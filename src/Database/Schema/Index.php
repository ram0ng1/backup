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

class Index
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique = false,
        public readonly bool $primary = false,
        /** Tipo específico do engine, opcional ("FULLTEXT", "SPATIAL", "GIN"…). */
        public readonly ?string $kind = null,
    ) {
    }
}
