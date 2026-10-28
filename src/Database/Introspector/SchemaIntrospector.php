<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Database\Introspector;

use Ramon\Backup\Database\Schema\Table;

interface SchemaIntrospector
{
    /**
     * Enumerate user tables (excluding views, system tables) in a stable
     * order so the dump is reproducible across ticks.
     *
     * @return list<string>
     */
    public function listTables(): array;

    /** Read full structure for one table into the engine-neutral model. */
    public function describeTable(string $table): Table;

    /**
     * Human-readable notes accumulated while introspecting the schema:
     * unsupported types we had to coerce, generated-column expressions
     * we couldn't translate, and similar lossy translations the admin
     * should know about. The list grows as `describeTable()` is called
     * across ticks; callers dedupe before showing.
     *
     * @return list<string>
     */
    public function warnings(): array;
}
