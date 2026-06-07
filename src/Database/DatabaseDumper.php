<?php

namespace Ramon\Backup\Database;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\Emitter\EmitterFactory;
use Ramon\Backup\Database\Emitter\SqlEmitter;
use Ramon\Backup\Database\Introspector\IntrospectorFactory;
use Ramon\Backup\Database\Introspector\SchemaIntrospector;
use Ramon\Backup\Database\Schema\ColumnType;
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

    /**
     * Linhas por SELECT para tabelas que podem guardar valores grandes
     * (famílias TEXT/BLOB/JSON ou VARCHAR/BINARY largos) — pequeno para
     * um lote nunca bufferizar dezenas de MB em PHP.
     */
    private const ROWS_PER_QUERY = 200;

    /**
     * Linhas por SELECT para tabelas só com escalares limitados (ints,
     * datas, strings curtas) — caso das grandes pivôs como `post_likes`.
     * O lote grande derruba o número de idas ao banco ~25×, o que faz uma
     * tabela de milhões de linhas ser dumpada em segundos. Seguro em
     * memória porque cada linha é minúscula; o keyset (ver dumpDataBatch)
     * é o que mantém um lote tão profundo ainda barato de buscar.
     */
    private const ROWS_PER_QUERY_BULK = 5000;

    /** Colunas string/binárias acima deste tamanho contam como "grandes". */
    private const WIDE_STRING_THRESHOLD = 1024;

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

    /**
     * `DROP TABLE IF EXISTS` for every user table, emitted in one block
     * right after the preamble and BEFORE any CREATE. Doing all drops up
     * front (rather than a DROP immediately before each table's CREATE)
     * makes a restore robust against any prior state on the destination:
     * a leftover child table from an earlier, differently-typed dump
     * can't make the parent's CREATE fail MySQL's FK column-type check,
     * and PostgreSQL parents drop cleanly via CASCADE without a manual
     * child-first ordering. FK enforcement is already suspended for the
     * load (preamble + the restorer), so drop order here is irrelevant.
     */
    public function dropAllTables(): string
    {
        $sql = '';
        foreach ($this->introspector->listTables() as $name) {
            $this->assertSafeIdent($name);
            $sql .= $this->emitter->emitDropTable($name);
        }
        return $sql;
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
     * Devolve o próximo lote de linhas de `$table`, retornando o SQL, a
     * quantidade de linhas consumidas e a chave da última linha emitida
     * para o próximo tick continuar de onde parou.
     *
     * Tabelas com primary key usam paginação por keyset, com o predicado
     * na forma EXPANDIDA `a > ? OR (a = ? AND b > ?)` (ver keysetPredicate)
     * em vez da tupla `(a, b) > (?, ?)`. A tupla é mais limpa, mas o
     * otimizador do MySQL 8 NÃO a converte em range scan — ela vira
     * `type=index` (varredura cheia do índice) e degrada para O(n²) em
     * chaves profundas, reproduzindo o "travado em 1,4M". A forma
     * expandida faz `type=range` (seek no índice), O(n) no total, e é
     * portável entre MySQL, MariaDB, PostgreSQL e SQLite.
     *
     * Tabelas sem primary key caem no fallback por `$offset` — correção
     * acima de velocidade; são raras e geralmente pequenas.
     *
     * `$afterKey` é o mapa coluna→valor da PK da última linha do tick
     * anterior (null no primeiro toque). SQL vazio com `consumed == 0`
     * sinaliza "tabela esgotada".
     *
     * @param array<string, mixed>|null $afterKey
     * @return array{sql: string, consumed: int, after_key: array<string, mixed>|null}
     */
    public function dumpDataBatch(string $table, int $offset, ?array $afterKey = null): array
    {
        $this->assertSafeIdent($table);
        $described = $this->describe($table);
        $tableQ    = $this->quoteIdentForRead($table);

        if (empty($described->primaryKey)) {
            $sql  = "SELECT * FROM $tableQ LIMIT ? OFFSET ?";
            $rows = $this->db->select($sql, [self::ROWS_PER_QUERY, $offset]);
            if (empty($rows)) {
                return ['sql' => '', 'consumed' => 0, 'after_key' => null];
            }

            $rowsAsArrays = array_map(fn ($r) => (array) $r, $rows);
            return [
                'sql'       => $this->emitter->emitInserts($described, $rowsAsArrays),
                'consumed'  => count($rows),
                'after_key' => null,
            ];
        }

        $pk        = $described->primaryKey;
        $orderCols = array_map(fn ($c) => $this->quoteIdentForRead($c), $pk);
        $orderBy   = ' ORDER BY ' . implode(', ', $orderCols);

        $bindings = [];
        $where = $afterKey !== null
            ? ' WHERE ' . $this->keysetPredicate($pk, $orderCols, $afterKey, $bindings)
            : '';
        $bindings[] = $this->batchSizeFor($described);

        $sql  = "SELECT * FROM $tableQ$where$orderBy LIMIT ?";
        $rows = $this->db->select($sql, $bindings);
        if (empty($rows)) {
            return ['sql' => '', 'consumed' => 0, 'after_key' => $afterKey];
        }

        $rowsAsArrays = array_map(fn ($r) => (array) $r, $rows);
        $last    = $rowsAsArrays[count($rowsAsArrays) - 1];
        $nextKey = [];
        foreach ($pk as $col) {
            $nextKey[$col] = $last[$col];
        }

        return [
            'sql'       => $this->emitter->emitInserts($described, $rowsAsArrays),
            'consumed'  => count($rows),
            'after_key' => $nextKey,
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

    /**
     * Conta as linhas de `$table` na fonte. Usado pelo driver de export
     * para mostrar progresso por tabela ("linha X de Y") no CLI e no
     * admin. Chamado uma vez por tabela (no primeiro toque), então o
     * custo do `COUNT(*)` — uma varredura de índice em InnoDB — é pago
     * uma única vez por tabela, não por lote.
     */
    public function countRows(string $table): int
    {
        $this->assertSafeIdent($table);
        $tableQ = $this->quoteIdentForRead($table);
        $row = $this->db->selectOne("SELECT COUNT(*) AS c FROM $tableQ");
        if (is_object($row)) {
            return (int) ($row->c ?? 0);
        }
        return (int) (is_array($row) ? ($row['c'] ?? 0) : 0);
    }

    /**
     * Monta o predicado de keyset na forma lexicográfica expandida para
     * uma PK de N colunas:
     *
     *   (a > ?)
     *   OR (a = ? AND b > ?)
     *   OR (a = ? AND b = ? AND c > ?)
     *
     * Cada cláusula usa apenas igualdades nas colunas anteriores e um `>`
     * na coluna corrente, o que o otimizador resolve como range scan no
     * índice da PK — ao contrário da tupla `(a,b,…) > (?,?,…)`. Os
     * bindings são anexados em `$bindings` na MESMA ordem dos `?`.
     *
     * @param list<string>             $pk         nomes crus das colunas da PK
     * @param list<string>             $orderCols  os mesmos nomes, já quotados
     * @param array<string, mixed>     $afterKey   valores da última linha
     * @param list<mixed>              $bindings   acumulador (por referência)
     */
    private function keysetPredicate(array $pk, array $orderCols, array $afterKey, array &$bindings): string
    {
        $clauses = [];
        $count = count($pk);
        for ($i = 0; $i < $count; $i++) {
            $conds = [];
            for ($j = 0; $j < $i; $j++) {
                $conds[] = $orderCols[$j] . ' = ?';
                $bindings[] = $afterKey[$pk[$j]];
            }
            $conds[] = $orderCols[$i] . ' > ?';
            $bindings[] = $afterKey[$pk[$i]];
            $clauses[] = '(' . implode(' AND ', $conds) . ')';
        }
        return '(' . implode(' OR ', $clauses) . ')';
    }

    /**
     * Escolhe o tamanho do lote de leitura. Qualquer coluna potencialmente
     * grande (TEXT/BLOB/JSON, ou VARCHAR/BINARY acima de
     * WIDE_STRING_THRESHOLD) força o lote pequeno para limitar a memória
     * por batch; tabelas só com escalares limitados usam o lote grande.
     */
    private function batchSizeFor(Table $table): int
    {
        foreach ($table->columns as $col) {
            $type = $col->type;
            $unbounded = in_array($type, [
                ColumnType::TEXT, ColumnType::MEDIUMTEXT, ColumnType::LONGTEXT,
                ColumnType::BLOB, ColumnType::MEDIUMBLOB, ColumnType::LONGBLOB,
                ColumnType::JSON,
            ], true);
            if ($unbounded) {
                return self::ROWS_PER_QUERY;
            }
            $bounded = in_array($type, [
                ColumnType::CHAR, ColumnType::VARCHAR,
                ColumnType::BINARY, ColumnType::VARBINARY,
            ], true);
            if ($bounded && (int) ($col->length ?? 0) > self::WIDE_STRING_THRESHOLD) {
                return self::ROWS_PER_QUERY;
            }
        }
        return self::ROWS_PER_QUERY_BULK;
    }

    private function describe(string $table): Table
    {
        if (! isset($this->describedCache[$table])) {
            $this->describedCache[$table] = $this->introspector->describeTable($table);
        }
        return $this->describedCache[$table];
    }
}
