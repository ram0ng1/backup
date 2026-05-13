<?php

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\Emitter\EmitterFactory;
use Ramon\Backup\Database\Emitter\SqlEmitter;
use Ramon\Backup\Database\Introspector\IntrospectorFactory;
use Ramon\Backup\Database\Introspector\SchemaIntrospector;
use Ramon\Backup\Database\Schema\Table;
use RuntimeException;

/**
 * Cross-engine, resumable database dumper.
 *
 * Architecture:
 *   - The SOURCE connection is read by a dialect-specific
 *     `SchemaIntrospector`, which produces an engine-neutral
 *     `Schema\Table` model.
 *   - The TARGET dialect (chosen at backup time by the admin) selects
 *     a `SqlEmitter`, which renders that neutral model + raw row data
 *     into the SQL the target engine expects.
 *
 * Output is plain SQL with one statement per logical block, separated
 * by `\n-- @@END@@\n`. The sentinel comment is what `DatabaseRestorer`
 * splits on — we don't try to parse semicolons because string literals
 * (and PG `$$` blocks) can contain them. The delimiter is a comment in
 * every supported engine, so the dump remains usable as a regular .sql
 * with any of the engines' command-line clients.
 *
 * Resumable shape:
 *   - The dumper is consumed via `dumpChunk()` style calls from
 *     `ExportJob`, which writes the SQL to a temp file and persists
 *     a small cursor between ticks.
 *   - State persisted between ticks: `phase` (schema|data|done),
 *     remaining tables, and the offset into the current table.
 */
class DatabaseDumper
{
    public const STATEMENT_DELIMITER = "\n-- @@END@@\n";

    public const PHASE_SCHEMA = 'schema';
    public const PHASE_DATA   = 'data';
    public const PHASE_DONE   = 'done';

    /** Rows pulled from a table per SELECT, regardless of byte budget. */
    private const ROWS_PER_QUERY = 200;

    private SchemaIntrospector $introspector;
    private SqlEmitter $emitter;

    /** Cache: table name → described neutral table (so we don't re-query). */
    private array $describedCache = [];

    public function __construct(
        protected Connection $db,
        ?Dialect $target = null,
    ) {
        $source = Dialect::detect($db);
        $this->introspector = IntrospectorFactory::for($db, $source);
        $this->emitter      = EmitterFactory::for($target ?? $source);
    }

    /** The dialect tag the produced SQL targets — recorded in archive meta. */
    public function targetTag(): string
    {
        return $this->emitter->targetTag();
    }

    /**
     * Lossy-translation notes accumulated during THIS instance's
     * lifetime — both from the introspector (unsupported source types,
     * generated columns, etc.) and from the emitter (e.g. PG skipping
     * a FULLTEXT or oversize-btree index). The caller (ExportJob)
     * merges them into the persistent job state so the UI can show
     * the union across all ticks at the end.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique(array_merge(
            $this->introspector->warnings(),
            $this->emitter->warnings(),
        )));
    }

    /**
     * Enumerate user tables. Returned in a stable order so the dump is
     * reproducible and resumable across ticks.
     *
     * @return list<string>
     */
    public function listTables(): array
    {
        return $this->introspector->listTables();
    }

    public function preamble(): string
    {
        return $this->emitter->preamble();
    }

    public function epilogue(): string
    {
        // Per-table fixups (PG sequence setval AND FK creation) are
        // rendered just before the session-level epilogue so the
        // emitter can rely on every row being in place — FKs added
        // here validate in one pass and pass cleanly on consistent
        // source data. The dumper instance is recreated per tick, so
        // we re-describe every table at end-of-stream rather than
        // trusting the in-memory cache.
        //
        // Always call the emitter regardless of "looks like there's
        // nothing to do" heuristics: only the emitter itself knows
        // whether it has FKs to add, sequences to bump, both, or
        // neither (returning '' when neither applies).
        $tail = '';
        foreach ($this->introspector->listTables() as $name) {
            $tail .= $this->emitter->emitPostDataFixups($this->describe($name));
        }
        return $tail . $this->emitter->epilogue();
    }

    /**
     * Drop + recreate DDL for one table. Keeping the public name
     * `dumpSchema` so the existing `ExportJob` driver loop is undisturbed.
     */
    public function dumpSchema(string $table): string
    {
        $this->assertSafeIdent($table);
        $described = $this->describe($table);
        return $this->emitter->emitSchema($described);
    }

    /**
     * Pull the next batch of rows from `$table` starting at `$offset`,
     * returning the SQL string and the number of rows consumed. The
     * caller accumulates offsets across ticks.
     *
     * Empty SQL with `consumed == 0` signals "table exhausted".
     *
     * @return array{sql: string, consumed: int}
     */
    public function dumpDataBatch(string $table, int $offset): array
    {
        $this->assertSafeIdent($table);
        $described = $this->describe($table);

        // Stable-ordered SELECT so OFFSET is meaningful across ticks.
        // Tables without a primary key fall back to natural order; this
        // is fine on read-only sources during a dump.
        $orderBy = $this->buildOrderBy($described);
        $tableQ  = $this->quoteIdentForRead($table);
        $sql     = "SELECT * FROM $tableQ" . $orderBy . " LIMIT ? OFFSET ?";

        $rows = $this->db->select($sql, [self::ROWS_PER_QUERY, $offset]);
        if (empty($rows)) {
            return ['sql' => '', 'consumed' => 0];
        }

        $rowsAsArrays = array_map(fn ($r) => (array) $r, $rows);
        $emitted = $this->emitter->emitInserts($described, $rowsAsArrays);
        return [
            'sql'      => $emitted,
            'consumed' => count($rows),
        ];
    }

    /**
     * One-line legacy quoter used only by the SELECT-path in
     * `dumpDataBatch` — it must match the SOURCE engine, not the
     * target. Engine-specific.
     */
    private function quoteIdentForRead(string $ident): string
    {
        $this->assertSafeIdent($ident);
        $source = Dialect::detect($this->db);
        if ($source->usesBackticks()) {
            return '`' . str_replace('`', '``', $ident) . '`';
        }
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /**
     * Reject identifiers that don't match a strict ASCII allowlist.
     * Tables and primary-key columns reaching this point originate from
     * schema introspection (`information_schema` / `sqlite_master`), so
     * normal data never trips this — it's a hard stop for a corrupted
     * catalog or a future code path that forwards request input.
     */
    private function assertSafeIdent(string $ident): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $ident)) {
            throw new RuntimeException('Invalid input');
        }
    }

    private function buildOrderBy(Table $table): string
    {
        if (empty($table->primaryKey)) return '';
        $cols = array_map(fn ($c) => $this->quoteIdentForRead($c), $table->primaryKey);
        return ' ORDER BY ' . implode(', ', $cols);
    }

    private function describe(string $table): Table
    {
        if (! isset($this->describedCache[$table])) {
            $this->describedCache[$table] = $this->introspector->describeTable($table);
        }
        return $this->describedCache[$table];
    }
}
