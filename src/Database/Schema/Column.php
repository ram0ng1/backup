<?php

namespace Ramon\Backup\Database\Schema;

/**
 * A single column in the engine-neutral schema model. `default` is
 * stored verbatim as captured from the source — emitters re-encode it
 * for the target dialect (e.g. CURRENT_TIMESTAMP, NULL, literal value).
 */
class Column
{
    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly bool $nullable = true,
        public readonly bool $autoIncrement = false,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly ?string $default = null,
        public readonly bool $defaultIsExpression = false,
        public readonly ?string $onUpdate = null,
        /** @var list<string>|null Enum values, when type is ENUM. */
        public readonly ?array $enumValues = null,
        public readonly bool $unsigned = false,
        public readonly ?string $comment = null,
    ) {
    }
}
