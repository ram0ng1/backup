<?php

namespace Ramon\Backup\Database\Introspector;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Database\Schema\Column;
use Ramon\Backup\Database\Schema\ColumnType;
use Ramon\Backup\Database\Schema\ForeignKey;
use Ramon\Backup\Database\Schema\Index;
use Ramon\Backup\Database\Schema\Table;
use RuntimeException;

/**
 * Reads schema from a live MySQL/MariaDB connection. Uses
 * `information_schema` so it works regardless of `SHOW CREATE TABLE`
 * permission quirks across managed hosts.
 *
 * MariaDB caveat: since 10.2.7, MariaDB returns COLUMN_DEFAULT as the
 * raw SQL expression string — string literals come wrapped in single
 * quotes (`'foo'`), and a NULL default is returned as the literal
 * four-character string `"NULL"`. MySQL still returns actual NULL and
 * a bare string. We normalise both shapes in `normaliseDefault()` so
 * downstream code sees the same neutral form regardless of source.
 */
class MysqlIntrospector implements SchemaIntrospector
{
    /** @var list<string> */
    private array $warnings = [];

    private bool $isMariaDb;

    public function __construct(
        protected Connection $db,
    ) {
        $this->isMariaDb = Dialect::detect($db) === Dialect::MARIADB;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function listTables(): array
    {
        $rows = $this->db->select(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
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
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE,
                    COLUMN_DEFAULT, EXTRA, CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            [$table]
        );
        if (empty($rows)) {
            throw new RuntimeException("Table not found: $table");
        }

        $columns = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $rawType   = strtolower((string) $r['DATA_TYPE']);
            $colType   = strtolower((string) $r['COLUMN_TYPE']);
            $extra     = strtolower((string) ($r['EXTRA'] ?? ''));
            $unsigned  = str_contains($colType, 'unsigned');
            $columnName = (string) $r['COLUMN_NAME'];

            // Generated columns store an expression that's evaluated
            // server-side. We can copy the values (PG/SQLite see them
            // as plain columns), but the GENERATION expression itself
            // would only make sense to MySQL/MariaDB and is dropped
            // silently by the emitters. Warn so the admin knows the
            // computed semantics won't follow the data.
            if (str_contains($extra, 'generated')) {
                $this->warnings[] = sprintf(
                    "Column `%s`.`%s` is a generated column; its expression is not portable across engines and will not be recreated on the destination — only the materialised values are copied.",
                    $table,
                    $columnName,
                );
            }

            [$type, $enumValues] = $this->mapType($rawType, $colType, $table, $columnName);

            $length    = $r['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $r['CHARACTER_MAXIMUM_LENGTH'] : null;
            $precision = $r['NUMERIC_PRECISION'] !== null ? (int) $r['NUMERIC_PRECISION'] : null;
            $scale     = $r['NUMERIC_SCALE'] !== null ? (int) $r['NUMERIC_SCALE'] : null;

            [$default, $defaultIsExpr] = $this->normaliseDefault($r['COLUMN_DEFAULT']);

            $onUpdate = null;
            if (str_contains($extra, 'on update')) {
                // EXTRA looks like "on update CURRENT_TIMESTAMP"
                $onUpdate = trim(substr($extra, strpos($extra, 'on update') + 9));
                $onUpdate = strtoupper($onUpdate);
            }

            $columns[] = new Column(
                name: (string) $r['COLUMN_NAME'],
                type: $type,
                nullable: strtoupper((string) $r['IS_NULLABLE']) === 'YES',
                autoIncrement: str_contains($extra, 'auto_increment'),
                length: $length,
                precision: $precision,
                scale: $scale,
                default: $default,
                defaultIsExpression: $defaultIsExpr,
                onUpdate: $onUpdate,
                enumValues: $enumValues,
                unsigned: $unsigned,
                comment: ($r['COLUMN_COMMENT'] ?? '') !== '' ? (string) $r['COLUMN_COMMENT'] : null,
            );
        }
        return $columns;
    }

    /**
     * Normalise `information_schema.COLUMNS.COLUMN_DEFAULT` into a
     * (literalValue|null, isExpression) pair that the rest of the
     * pipeline expects.
     *
     * Three sources to reconcile:
     *
     *   - MySQL ≤ 8.0: returns actual NULL for "no default" and the
     *     raw literal (`foo`, `0`, `CURRENT_TIMESTAMP`) for everything
     *     else — no quoting.
     *
     *   - MariaDB ≥ 10.2.7: returns the SQL expression text. NULL
     *     defaults come back as the four-char string `"NULL"`; string
     *     literals come wrapped in single quotes (`'foo'`, with
     *     embedded `''` doubling SQL-style). Expression defaults
     *     (CURRENT_TIMESTAMP, NOW(), arithmetic) come unquoted.
     *
     *   - "no default" on a nullable column: MySQL returns SQL NULL,
     *     MariaDB returns the string `"NULL"`. Both should map to
     *     PHP null with no expression flag.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function normaliseDefault(mixed $raw): array
    {
        if ($raw === null) return [null, false];

        $s = (string) $raw;

        // MariaDB encodes "no default" as the literal text `NULL`.
        if ($this->isMariaDb && $s === 'NULL') return [null, false];

        // MariaDB-quoted string literal: peel one layer of single
        // quotes and undo the SQL-style `''` doubling. MySQL ≤ 8.0
        // never wraps with quotes, so this branch is MariaDB-only.
        if ($this->isMariaDb && strlen($s) >= 2 && $s[0] === "'" && substr($s, -1) === "'") {
            $inner = substr($s, 1, -1);
            return [str_replace("''", "'", $inner), false];
        }

        // Recognised time-of-row expressions — flagged so the emitter
        // renders them unquoted (CURRENT_TIMESTAMP, not 'CURRENT_TIMESTAMP').
        $upper = strtoupper($s);
        if (in_array($upper, ['CURRENT_TIMESTAMP', 'NOW()', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
            return [$upper, true];
        }

        // MariaDB also reports `current_timestamp()` variants and
        // arithmetic expressions unquoted. If it parses as numeric,
        // it's a literal number; otherwise treat as expression so the
        // emitter passes it through verbatim.
        if ($this->isMariaDb && ! is_numeric($s)) {
            return [$s, true];
        }

        return [$s, false];
    }

    /**
     * Map a MySQL DATA_TYPE / COLUMN_TYPE pair to the neutral
     * ColumnType enum, plus any extracted ENUM values. Records a
     * warning when the source uses a type we can't fully model
     * (SET, spatial types, BIT(>1), and any unknown engine-specific
     * type) — those values still travel as TEXT/string but the
     * destination column will not enforce the same semantics.
     *
     * @return array{0: ColumnType, 1: list<string>|null}
     */
    private function mapType(string $dataType, string $columnType, string $table, string $column): array
    {
        $type = match ($dataType) {
            'tinyint'    => str_contains($columnType, '(1)') ? ColumnType::BOOL : ColumnType::TINYINT,
            'smallint'   => ColumnType::SMALLINT,
            'mediumint',
            'int',
            'integer'    => ColumnType::INTEGER,
            'bigint'     => ColumnType::BIGINT,
            'decimal',
            'numeric'    => ColumnType::DECIMAL,
            'float'      => ColumnType::FLOAT,
            'double',
            'real'       => ColumnType::DOUBLE,
            'char'       => ColumnType::CHAR,
            'varchar'    => ColumnType::VARCHAR,
            'tinytext'   => ColumnType::TEXT,
            'text'       => ColumnType::TEXT,
            'mediumtext' => ColumnType::MEDIUMTEXT,
            'longtext'   => ColumnType::LONGTEXT,
            'binary'     => ColumnType::BINARY,
            'varbinary'  => ColumnType::VARBINARY,
            'tinyblob',
            'blob'       => ColumnType::BLOB,
            'mediumblob' => ColumnType::MEDIUMBLOB,
            'longblob'   => ColumnType::LONGBLOB,
            'date'       => ColumnType::DATE,
            'time'       => ColumnType::TIME,
            'datetime'   => ColumnType::DATETIME,
            'timestamp'  => ColumnType::TIMESTAMP,
            'year'       => ColumnType::SMALLINT,
            'json'       => ColumnType::JSON,
            'enum'       => ColumnType::ENUM,
            // SET values are comma-separated strings on the wire — TEXT
            // is correct, only the SET-specific semantics are lost.
            'set'        => $this->warnLossyType($table, $column, $dataType,
                            'multi-value SET semantics are dropped; values travel as a comma-joined string',
                            ColumnType::TEXT),
            // BIT(1) is the canonical "boolean in MySQL"; BIT(>1) is
            // binary and only survives intact in a binary column.
            'bit'        => str_contains($columnType, '(1)')
                              ? ColumnType::BOOL
                              : $this->warnLossyType($table, $column, $columnType,
                                  'multi-bit BIT() has no native equivalent — values travel as raw bytes',
                                  ColumnType::BLOB),
            // Spatial types carry WKB binary bytes; they can't round-
            // trip through TEXT on PG/SQLite (UTF-8 validation will
            // reject them) so we route them through BLOB instead.
            'point', 'linestring', 'polygon', 'geometry',
            'multipoint', 'multilinestring', 'multipolygon',
            'geometrycollection'
                         => $this->warnLossyType($table, $column, $dataType,
                            'spatial types are not portable; values are stored as raw bytes (BLOB) on the destination',
                            ColumnType::BLOB),
            default      => $this->warnLossyType($table, $column, $dataType,
                            'unrecognised MySQL/MariaDB type; coerced to TEXT — may not round-trip cleanly',
                            ColumnType::TEXT),
        };

        $enumValues = null;
        if ($type === ColumnType::ENUM && preg_match("/enum\((.+)\)/i", $columnType, $m)) {
            // Members come quoted with single quotes and comma-separated.
            $enumValues = [];
            foreach (str_getcsv($m[1], ',', "'", '\\') as $v) {
                $enumValues[] = (string) $v;
            }
        }

        return [$type, $enumValues];
    }

    /**
     * Record a warning and return the caller-chosen fallback type.
     * Splitting this out keeps the mapType match arms one line each
     * and the warning copy in one place. The fallback type is a
     * parameter rather than a constant because spatial / BIT(>1) data
     * needs BLOB to survive (TEXT would fail UTF-8 validation in PG).
     */
    private function warnLossyType(string $table, string $column, string $sourceType, string $detail, ColumnType $fallback): ColumnType
    {
        $this->warnings[] = sprintf(
            "Column `%s`.`%s` uses MySQL/MariaDB type `%s` — %s.",
            $table,
            $column,
            $sourceType,
            $detail,
        );
        return $fallback;
    }

    /** @return list<string> */
    private function readPrimaryKey(string $table): array
    {
        $rows = $this->db->select(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             AND INDEX_NAME = 'PRIMARY'
             ORDER BY SEQ_IN_INDEX",
            [$table]
        );
        $cols = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $cols[] = (string) $r['COLUMN_NAME'];
        }
        return $cols;
    }

    /** @return list<Index> */
    private function readIndexes(string $table): array
    {
        $rows = $this->db->select(
            "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$table]
        );
        $byName = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $name = (string) $r['INDEX_NAME'];
            if ($name === 'PRIMARY') continue;
            $byName[$name] ??= [
                'name'    => $name,
                'columns' => [],
                'unique'  => ((int) $r['NON_UNIQUE']) === 0,
                'kind'    => null,
            ];
            $type = strtoupper((string) ($r['INDEX_TYPE'] ?? ''));
            if (in_array($type, ['FULLTEXT', 'SPATIAL'], true)) {
                $byName[$name]['kind'] = $type;
            }
            $byName[$name]['columns'][] = (string) $r['COLUMN_NAME'];
        }
        $out = [];
        foreach ($byName as $idx) {
            $out[] = new Index(
                name: $idx['name'],
                columns: $idx['columns'],
                unique: $idx['unique'],
                primary: false,
                kind: $idx['kind'],
            );
        }
        return $out;
    }

    /** @return list<ForeignKey> */
    private function readForeignKeys(string $table): array
    {
        $rows = $this->db->select(
            "SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE,
                    k.ORDINAL_POSITION
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                  AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ?
                   AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            [$table]
        );
        $byName = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $name = (string) $r['CONSTRAINT_NAME'];
            $byName[$name] ??= [
                'name'        => $name,
                'columns'     => [],
                'refTable'    => (string) $r['REFERENCED_TABLE_NAME'],
                'refColumns'  => [],
                'onDelete'    => (string) ($r['DELETE_RULE'] ?? ''),
                'onUpdate'    => (string) ($r['UPDATE_RULE'] ?? ''),
            ];
            $byName[$name]['columns'][]    = (string) $r['COLUMN_NAME'];
            $byName[$name]['refColumns'][] = (string) $r['REFERENCED_COLUMN_NAME'];
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
