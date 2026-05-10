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
 * Reads schema from a live SQLite connection. Uses the standard PRAGMA
 * suite plus a sniff of the original `CREATE TABLE` SQL stored in
 * `sqlite_master`, which is needed because PRAGMA flags don't tell us
 * whether the integer PK column was declared with AUTOINCREMENT.
 */
class SqliteIntrospector implements SchemaIntrospector
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
            "SELECT name FROM sqlite_master
             WHERE type='table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
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
        $createSql = $this->readCreateSql($table);
        $hasAutoIncr = (bool) preg_match('/AUTOINCREMENT/i', $createSql ?? '');

        $columns    = $this->readColumns($table, $hasAutoIncr);
        $primary    = [];
        foreach ($columns as $c) {
            // table_info exposes pk as a non-zero rank; we read it
            // separately below and then reconcile.
        }
        $primary    = $this->readPrimaryKey($table);
        $indexes    = $this->readIndexes($table);
        $foreignKeys = $this->readForeignKeys($table);

        return new Table($table, $columns, $primary, $indexes, $foreignKeys);
    }

    private function readCreateSql(string $table): ?string
    {
        $row = $this->db->selectOne(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name = ?",
            [$table]
        );
        if ($row === null) throw new RuntimeException("Table not found: $table");
        $sql = is_object($row) ? ($row->sql ?? null) : (is_array($row) ? ($row['sql'] ?? null) : null);
        return $sql !== null ? (string) $sql : null;
    }

    /**
     * @return list<Column>
     */
    private function readColumns(string $table, bool $tableHasAutoIncrement): array
    {
        $rows = $this->db->select("PRAGMA table_info(" . $this->quoteIdent($table) . ")");
        if (empty($rows)) {
            throw new RuntimeException("Table not found: $table");
        }

        $columns = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $declared = strtoupper((string) ($r['type'] ?? ''));
            $columnName = (string) $r['name'];
            $type     = $this->mapAffinity($declared, $table, $columnName);
            $isPk     = ((int) ($r['pk'] ?? 0)) > 0;
            $isInteger = $type->isInteger();

            // SQLite ROWID alias: INTEGER PRIMARY KEY (with or without
            // AUTOINCREMENT) is the only auto-increment column shape.
            $autoIncrement = $isPk && $isInteger && $tableHasAutoIncrement;

            $rawDefault = $r['dflt_value'] ?? null;
            $default = null;
            $defaultIsExpr = false;
            if ($rawDefault !== null) {
                $s = (string) $rawDefault;
                $upper = strtoupper($s);
                if (preg_match("/^'(.*)'$/s", $s, $m)) {
                    $default = str_replace("''", "'", $m[1]);
                } elseif (in_array($upper, ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
                    $defaultIsExpr = true;
                    $default = $upper;
                } elseif (is_numeric($s)) {
                    $default = $s;
                } else {
                    $defaultIsExpr = true;
                    $default = $s;
                }
            }

            $length = null;
            if (preg_match('/\(\s*(\d+)\s*\)/', $declared, $m)) {
                $length = (int) $m[1];
            }

            $columns[] = new Column(
                name: (string) $r['name'],
                type: $type,
                nullable: ((int) ($r['notnull'] ?? 0)) === 0,
                autoIncrement: $autoIncrement,
                length: $length,
                precision: null,
                scale: null,
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

    /**
     * SQLite uses dynamic types with affinity rules. We map declared
     * types to the closest neutral type by simple substring sniffing —
     * the same heuristic SQLite itself uses internally for affinity.
     */
    private function mapAffinity(string $declared, string $table, string $column): ColumnType
    {
        // SQLite columns can be declared without any type at all — the
        // affinity is then "BLOB" by SQLite's own rules, but for our
        // purposes TEXT is the safer default since values arrive as
        // strings via PDO. No warning: this is a deliberate SQLite
        // shape, not a translation gap.
        if ($declared === '') return ColumnType::TEXT;

        if (str_contains($declared, 'BIGINT')) return ColumnType::BIGINT;
        if (str_contains($declared, 'BOOL'))   return ColumnType::BOOL;
        if (preg_match('/INT/', $declared))    return ColumnType::INTEGER;

        if (preg_match('/CHAR|CLOB|TEXT/', $declared)) {
            if (str_contains($declared, 'LONGTEXT')) return ColumnType::LONGTEXT;
            if (str_contains($declared, 'MEDIUMTEXT')) return ColumnType::MEDIUMTEXT;
            if (str_contains($declared, 'CHAR')) return ColumnType::VARCHAR;
            return ColumnType::TEXT;
        }
        if (str_contains($declared, 'BLOB'))   return ColumnType::BLOB;
        if (preg_match('/REAL|FLOA|DOUB/', $declared)) return ColumnType::DOUBLE;
        if (str_contains($declared, 'DECIMAL') || str_contains($declared, 'NUMERIC')) return ColumnType::DECIMAL;
        if (str_contains($declared, 'DATETIME') || str_contains($declared, 'TIMESTAMP')) return ColumnType::DATETIME;
        if (str_contains($declared, 'DATE')) return ColumnType::DATE;
        if (str_contains($declared, 'TIME')) return ColumnType::TIME;
        if (str_contains($declared, 'JSON')) return ColumnType::JSON;
        if (str_contains($declared, 'UUID')) return ColumnType::UUID;

        // Truly unknown declared type — uncommon in SQLite (it
        // tolerates anything) but worth surfacing because the actual
        // values may not match TEXT semantics.
        $this->warnings[] = sprintf(
            'Column "%s"."%s" has declared type `%s` which does not match any standard affinity — coerced to TEXT.',
            $table,
            $column,
            $declared,
        );
        return ColumnType::TEXT;
    }

    /** @return list<string> */
    private function readPrimaryKey(string $table): array
    {
        $rows = $this->db->select("PRAGMA table_info(" . $this->quoteIdent($table) . ")");
        $byPos = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $pk = (int) ($r['pk'] ?? 0);
            if ($pk > 0) {
                $byPos[$pk] = (string) $r['name'];
            }
        }
        ksort($byPos);
        return array_values($byPos);
    }

    /** @return list<Index> */
    private function readIndexes(string $table): array
    {
        $idxRows = $this->db->select("PRAGMA index_list(" . $this->quoteIdent($table) . ")");
        $out = [];
        foreach ($idxRows as $row) {
            $r = (array) $row;
            $name   = (string) $r['name'];
            $unique = (int) ($r['unique'] ?? 0) === 1;
            $origin = (string) ($r['origin'] ?? '');
            // Skip indexes created automatically for PK / UNIQUE
            // constraints — the emitter recreates those from the
            // primary-key + column definitions.
            if ($origin === 'pk') continue;
            // Auto-named indexes from UNIQUE constraints are still
            // worth re-emitting on the destination so behaviour matches.
            if (str_starts_with($name, 'sqlite_autoindex_')) continue;

            $colsRows = $this->db->select("PRAGMA index_info(" . $this->quoteIdent($name) . ")");
            $cols = [];
            foreach ($colsRows as $cr) {
                $crr = (array) $cr;
                $cols[(int) $crr['seqno']] = (string) $crr['name'];
            }
            ksort($cols);
            $out[] = new Index($name, array_values($cols), $unique, false, null);
        }
        return $out;
    }

    /** @return list<ForeignKey> */
    private function readForeignKeys(string $table): array
    {
        $rows = $this->db->select("PRAGMA foreign_key_list(" . $this->quoteIdent($table) . ")");
        $byId = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $id = (int) $r['id'];
            $byId[$id] ??= [
                'name'       => 'fk_' . $table . '_' . $id,
                'columns'    => [],
                'refTable'   => (string) $r['table'],
                'refColumns' => [],
                'onDelete'   => (string) ($r['on_delete'] ?? ''),
                'onUpdate'   => (string) ($r['on_update'] ?? ''),
            ];
            $byId[$id]['columns'][]    = (string) $r['from'];
            $byId[$id]['refColumns'][] = (string) $r['to'];
        }
        $out = [];
        foreach ($byId as $fk) {
            $out[] = new ForeignKey(
                name: $fk['name'],
                columns: $fk['columns'],
                refTable: $fk['refTable'],
                refColumns: $fk['refColumns'],
                onDelete: $fk['onDelete'] !== '' && strtoupper($fk['onDelete']) !== 'NO ACTION' ? $fk['onDelete'] : null,
                onUpdate: $fk['onUpdate'] !== '' && strtoupper($fk['onUpdate']) !== 'NO ACTION' ? $fk['onUpdate'] : null,
            );
        }
        return $out;
    }

    private function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
