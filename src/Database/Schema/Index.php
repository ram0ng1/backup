<?php

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
        /** Optional engine-specific kind ("FULLTEXT", "SPATIAL", "GIN"…). */
        public readonly ?string $kind = null,
    ) {
    }
}
