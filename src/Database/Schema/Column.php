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

/**
 * Coluna do modelo neutro. `default` guarda o valor como veio da origem;
 * cada emitter o recodifica para o dialeto alvo.
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
        /** @var list<string>|null Valores do ENUM, quando o tipo é ENUM. */
        public readonly ?array $enumValues = null,
        public readonly bool $unsigned = false,
        public readonly ?string $comment = null,
    ) {
    }
}
