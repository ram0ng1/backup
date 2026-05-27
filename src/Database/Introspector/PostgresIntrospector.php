<?php

namespace Ramon\Backup\Database\Introspector;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\Schema\Column;
use Ramon\Backup\Database\Schema\ColumnType;
use Ramon\Backup\Database\Schema\ForeignKey;
use Ramon\Backup\Database\Schema\Index;
use Ramon\Backup\Database\Schema\Table;
use RuntimeException;

/**
 * Reads schema from a live PostgreSQL connection. Uses the standard
 * `information_schema.*` plus a small handful of `pg_catalog` queries
 * for things SQL-standard catalogs don't expose (sequence detection,
 * index column ordering).
 *
 * Schema scope: `current_schema()` (typically "public").
 */
class PostgresIntrospector implements SchemaIntrospector
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        protected Connection $db,
    ) {
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function listTables(): array
    {
        $rows = $this->db->select(
            "SELECT tablename FROM pg_catalog.pg_tables
             WHERE schemaname = current_schema()
             ORDER BY tablename"
        );
        $out = [];
        foreach ($rows as $row) {
            $vals = array_values((array) $row);
            $out[] = (string) $vals[0];
        }
        return $out;
    }

    public function describeTable(string $table): Table
    {
        $columns    = $this->readColumns($table);
        $primary    = $this->readPrimaryKey($table);
        $indexes    = $this->readIndexes($table);
        $foreignKeys = $this->readForeignKeys($table);

        return new Table($table, $columns, $primary, $indexes, $foreignKeys);
    }

    /** @return list<Column> */
    private function readColumns(string $table): array
    {
        $rows = $this->db->select(
            "SELECT column_name, data_type, udt_name, is_nullable,
                    column_default, character_maximum_length,
                    numeric_precision, numeric_scale, is_identity,
                    identity_generation
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ?
             ORDER BY ordinal_position",
            [$table]
        );
        if (empty($rows)) {
            throw new RuntimeException("Table not found: $table");
        }

        $columns = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $dataType = strtolower((string) $r['data_type']);
            $udt      = strtolower((string) ($r['udt_name'] ?? ''));

            $columnName = (string) $r['column_name'];
            $type = $this->mapType($dataType, $udt, $table, $columnName);

            $rawDefault = $r['column_default'];
            $isIdentity = strtoupper((string) ($r['is_identity'] ?? 'NO')) === 'YES';
            $autoIncrement = $isIdentity || (
                $rawDefault !== null && str_starts_with(strtolower((string) $rawDefault), 'nextval(')
            );

            $defaultIsExpr = false;
            $default = null;
            if ($rawDefault !== null && ! $autoIncrement) {
                $s = (string) $rawDefault;
                $upper = strtoupper($s);
                // PG wraps literal defaults like 'foo'::text — peel
                // both the quotes and the cast.
                if (preg_match("/^'((?:[^']|'')*)'::/i", $s, $m)) {
                    $default = str_replace("''", "'", $m[1]);
                } elseif ($upper === 'NULL') {
                    $default = null;
                } elseif (str_contains($upper, 'CURRENT_TIMESTAMP') || str_contains($upper, 'NOW()')) {
                    $defaultIsExpr = true;
                    $default = 'CURRENT_TIMESTAMP';
                } elseif ($upper === 'TRUE' || $upper === 'FALSE') {
                    // PG boolean literal: stash as a plain string so
                    // the BOOL-aware path in each emitter picks it up
                    // and renders the right per-dialect form (1/0 for
                    // MySQL, TRUE/FALSE for PG). Marking it as an
                    // expression would emit the bareword `false`
                    // verbatim, which is invalid in MySQL DDL.
                    $default = $upper === 'TRUE' ? 'true' : 'false';
                } elseif (is_numeric($s)) {
                    $default = $s;
                } else {
                    // Unknown expression — mark as expression so it's
                    // emitted verbatim by emitters that understand it.
                    $defaultIsExpr = true;
                    $default = $s;
                }
            }

            $columns[] = new Column(
                name: (string) $r['column_name'],
                type: $type,
                nullable: strtoupper((string) $r['is_nullable']) === 'YES',
                autoIncrement: $autoIncrement,
                length: $r['character_maximum_length'] !== null ? (int) $r['character_maximum_length'] : null,
                precision: $r['numeric_precision'] !== null ? (int) $r['numeric_precision'] : null,
                scale: $r['numeric_scale'] !== null ? (int) $r['numeric_scale'] : null,
                default: $default,
                defaultIsExpression: $defaultIsExpr,
                onUpdate: null,
                enumValues: null,
                unsigned: false,
                comment: null,
            );
        }
        return $columns;
    }

    private function mapType(string $dataType, string $udt, string $table, string $column): ColumnType
    {
        $known = match (true) {
            $dataType === 'boolean'                 => ColumnType::BOOL,
            $dataType === 'smallint'                => ColumnType::SMALLINT,
            $dataType === 'integer'                 => ColumnType::INTEGER,
            $dataType === 'bigint'                  => ColumnType::BIGINT,
            $dataType === 'numeric',
            $dataType === 'decimal'                 => ColumnType::DECIMAL,
            $dataType === 'real'                    => ColumnType::FLOAT,
            $dataType === 'double precision'        => ColumnType::DOUBLE,
            $dataType === 'character'               => ColumnType::CHAR,
            $dataType === 'character varying'       => ColumnType::VARCHAR,
            $dataType === 'text'                    => ColumnType::TEXT,
            $dataType === 'bytea'                   => ColumnType::BLOB,
            $dataType === 'date'                    => ColumnType::DATE,
            str_starts_with($dataType, 'time')
                && ! str_contains($dataType, 'stamp') => ColumnType::TIME,
            str_starts_with($dataType, 'timestamp') => ColumnType::TIMESTAMP,
            $dataType === 'json' || $dataType === 'jsonb' => ColumnType::JSON,
            $dataType === 'uuid'                    => ColumnType::UUID,
            // Arrays, ranges, geometric types, network types, money,
            // tsvector, custom types, etc. — anything outside the SQL
            // standard core. PG can serialise them as text via
            // implicit cast on SELECT, but the destination engine
            // won't reconstruct the type semantics.
            default                                 => null,
        };

        if ($known !== null) return $known;

        $this->warnings[] = sprintf(
            'Column "%s"."%s" uses PostgreSQL type `%s` (udt %s) — coerced to TEXT; values may not round-trip if the destination cannot parse the textual form.',
            $table,
            $column,
            $dataType,
            $udt !== '' ? $udt : 'unknown',
        );
        return ColumnType::TEXT;
    }

    /** @return list<string> */
    private function readPrimaryKey(string $table): array
    {
        $rows = $this->db->select(
            "SELECT a.attname AS column_name
             FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             WHERE i.indrelid = (
                       SELECT c.oid FROM pg_class c
                       JOIN pg_namespace n ON n.oid = c.relnamespace
                       WHERE c.relname = ? AND n.nspname = current_schema()
                   )
                   AND i.indisprimary
             ORDER BY array_position(i.indkey, a.attnum)",
            [$table]
        );
        $cols = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $cols[] = (string) $r['column_name'];
        }
        return $cols;
    }

    /** @return list<Index> */
    private function readIndexes(string $table): array
    {
        $rows = $this->db->select(
            "SELECT i.relname AS index_name,
                    ix.indisunique AS is_unique,
                    ix.indisprimary AS is_primary,
                    a.attname AS column_name,
                    array_position(ix.indkey, a.attnum) AS pos
             FROM pg_class t
             JOIN pg_namespace n   ON n.oid = t.relnamespace
             JOIN pg_index ix      ON ix.indrelid = t.oid
             JOIN pg_class i       ON i.oid = ix.indexrelid
             JOIN pg_attribute a   ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
             WHERE t.relname = ? AND n.nspname = current_schema()
             ORDER BY i.relname, pos",
            [$table]
        );
        $byName = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            if ($r['is_primary']) continue;
            $name = (string) $r['index_name'];
            $byName[$name] ??= [
                'name'    => $name,
                'columns' => [],
                'unique'  => (bool) $r['is_unique'],
            ];
            $byName[$name]['columns'][] = (string) $r['column_name'];
        }
        $out = [];
        foreach ($byName as $idx) {
            $out[] = new Index($idx['name'], $idx['columns'], $idx['unique'], false, null);
        }
        return $out;
    }

    /** @return list<ForeignKey> */
    private function readForeignKeys(string $table): array
    {
        $rows = $this->db->select(
            "SELECT tc.constraint_name, kcu.column_name,
                    ccu.table_name AS ref_table, ccu.column_name AS ref_column,
                    rc.update_rule, rc.delete_rule, kcu.ordinal_position
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
                   ON kcu.constraint_name = tc.constraint_name
                  AND kcu.constraint_schema = tc.constraint_schema
             JOIN information_schema.referential_constraints rc
                   ON rc.constraint_name = tc.constraint_name
                  AND rc.constraint_schema = tc.constraint_schema
             JOIN information_schema.constraint_column_usage ccu
                   ON ccu.constraint_name = tc.constraint_name
                  AND ccu.constraint_schema = tc.constraint_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
                   AND tc.table_schema = current_schema()
                   AND tc.table_name = ?
             ORDER BY tc.constraint_name, kcu.ordinal_position",
            [$table]
        );
        $byName = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $name = (string) $r['constraint_name'];
            $byName[$name] ??= [
                'name'        => $name,
                'columns'     => [],
                'refTable'    => (string) $r['ref_table'],
                'refColumns'  => [],
                'onDelete'    => (string) $r['delete_rule'],
                'onUpdate'    => (string) $r['update_rule'],
            ];
            $byName[$name]['columns'][]    = (string) $r['column_name'];
            $byName[$name]['refColumns'][] = (string) $r['ref_column'];
        }
        $out = [];
        foreach ($byName as $fk) {
            $out[] = new ForeignKey(
                name: $fk['name'],
                columns: $fk['columns'],
                refTable: $fk['refTable'],
                refColumns: $fk['refColumns'],
                onDelete: $fk['onDelete'] ?: null,
                onUpdate: $fk['onUpdate'] ?: null,
            );
        }
        return $out;
    }
}
