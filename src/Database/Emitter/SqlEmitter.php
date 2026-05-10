<?php

namespace Ramon\Backup\Database\Emitter;

use Ramon\Backup\Database\Schema\Table;

/**
 * One emitter per target dialect. The dumper feeds it the
 * engine-neutral schema and a stream of typed rows; the emitter
 * produces the actual `CREATE TABLE` / `INSERT` SQL for that dialect.
 *
 * Statements are separated by `\n-- @@END@@\n` (the dumper's universal
 * delimiter), which is a comment in every dialect we target.
 */
interface SqlEmitter
{
    /** Header lines that prepare the session (FK toggle, encoding, etc.). */
    public function preamble(): string;

    /** Footer lines that re-enable any session toggles set in preamble(). */
    public function epilogue(): string;

    /** `CREATE TABLE` (and any auxiliary statements) for one neutral table. */
    public function emitSchema(Table $table): string;

    /**
     * `INSERT INTO …` for a batch of rows. `$rows` is a list of
     * column → typed PHP value maps (the source connection's native
     * row representation). The emitter is responsible for type-correct
     * quoting.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function emitInserts(Table $table, array $rows): string;

    /**
     * Per-table tail statements emitted after all data is loaded —
     * used by PostgreSQL to resync sequences with MAX(col)+1, no-op
     * for the others.
     */
    public function emitPostDataFixups(Table $table): string;

    /** The dialect tag stored in the archive meta header. */
    public function targetTag(): string;
}
