<?php

namespace Ramon\Backup\Database\Schema;

class Table
{
    /**
     * @param list<Column>     $columns
     * @param list<string>     $primaryKey
     * @param list<Index>      $indexes
     * @param list<ForeignKey> $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $primaryKey = [],
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
    ) {
    }

    public function column(string $name): ?Column
    {
        foreach ($this->columns as $c) {
            if ($c->name === $name) return $c;
        }
        return null;
    }

    /** @return list<string> */
    public function autoIncrementColumns(): array
    {
        $out = [];
        foreach ($this->columns as $c) {
            if ($c->autoIncrement) $out[] = $c->name;
        }
        return $out;
    }
}
