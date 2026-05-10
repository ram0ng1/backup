<?php

namespace Ramon\Backup\Database\Emitter;

use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Database\Schema\Column;
use Ramon\Backup\Database\Schema\ColumnType;
use Ramon\Backup\Database\Schema\Table;

/**
 * Emitter for SQLite 3.35+. The 3.35 floor is for `ALTER TABLE … DROP
 * COLUMN` support and well-defined RETURNING — the data path here only
 * needs the much older bits, but the extension's overall floor matches.
 *
 * Notes:
 *   - SQLite uses dynamic typing; we still emit declared types so other
 *     tools that read the dump understand intent.
 *   - AUTOINCREMENT is only valid on `INTEGER PRIMARY KEY`. When the
 *     source has an auto-incrementing column with a different shape
 *     (e.g. BIGINT), we emit it as `INTEGER PRIMARY KEY AUTOINCREMENT`
 *     — SQLite stores integers as 64-bit anyway, so the value range is
 *     preserved.
 *   - Foreign keys are deferred during the data load via
 *     `PRAGMA foreign_keys = OFF` in the preamble.
 */
class SqliteEmitter extends AbstractEmitter
{
    protected function identQuote(): string
    {
        return '"';
    }

    public function targetTag(): string
    {
        return Dialect::SQLITE->value;
    }

    public function preamble(): string
    {
        $now = gmdate('Y-m-d H:i:s');
        return implode($this->delimiter(), [
            "-- Flarum backup — database dump (target: sqlite)",
            "-- Generated at $now UTC",
            "PRAGMA foreign_keys = OFF",
        ]) . $this->delimiter();
    }

    public function epilogue(): string
    {
        return 'PRAGMA foreign_keys = ON' . $this->delimiter();
    }

    public function emitSchema(Table $table): string
    {
        $name = $this->quoteIdent($table->name);

        // Detect "single integer-PK auto-increment" — that case must
        // collapse into the INTEGER PRIMARY KEY AUTOINCREMENT shape
        // because SQLite refuses AUTOINCREMENT on any other column.
        $autoCols = $table->autoIncrementColumns();
        $singleAutoPk = (count($autoCols) === 1
            && count($table->primaryKey) === 1
            && $autoCols[0] === $table->primaryKey[0]);

        $lines = [];
        foreach ($table->columns as $col) {
            $isPkAuto = $singleAutoPk && $col->name === $autoCols[0];
            $lines[] = '  ' . $this->columnDdl($col, $isPkAuto);
        }
        if (! $singleAutoPk && ! empty($table->primaryKey)) {
            $lines[] = '  PRIMARY KEY (' . $this->columnList($table->primaryKey) . ')';
        }
        foreach ($table->foreignKeys as $fk) {
            $line = '  CONSTRAINT ' . $this->quoteIdent($fk->name)
                . ' FOREIGN KEY (' . $this->columnList($fk->columns) . ')'
                . ' REFERENCES ' . $this->quoteIdent($fk->refTable)
                . ' (' . $this->columnList($fk->refColumns) . ')';
            if ($fk->onDelete) $line .= ' ON DELETE ' . $fk->onDelete;
            if ($fk->onUpdate) $line .= ' ON UPDATE ' . $fk->onUpdate;
            $lines[] = $line;
        }

        $body = implode(",\n", $lines);
        $create = "CREATE TABLE $name (\n$body\n)";
        $create = preg_replace('/\s+/', ' ', $create);

        $sql = 'DROP TABLE IF EXISTS ' . $name . $this->delimiter()
             . $create . $this->delimiter();

        foreach ($table->indexes as $idx) {
            if ($idx->primary) continue;
            $unique = $idx->unique ? 'UNIQUE ' : '';
            $sql .= 'CREATE ' . $unique . 'INDEX ' . $this->quoteIdent($idx->name)
                . ' ON ' . $name . ' (' . $this->columnList($idx->columns) . ')'
                . $this->delimiter();
        }
        return $sql;
    }

    public function emitInserts(Table $table, array $rows): string
    {
        if (empty($rows)) return '';
        $name = $this->quoteIdent($table->name);
        $columns = array_map(fn (Column $c) => $c->name, $table->columns);
        $colList = $this->columnList($columns);

        $valueGroups = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($table->columns as $col) {
                $vals[] = $this->quoteValue($col, $row[$col->name] ?? null);
            }
            $valueGroups[] = '(' . implode(',', $vals) . ')';
        }

        return 'INSERT INTO ' . $name . ' (' . $colList . ') VALUES '
            . implode(',', $valueGroups) . $this->delimiter();
    }

    /** @param list<string> $columns */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map(fn ($c) => $this->quoteIdent($c), $columns));
    }

    private function columnDdl(Column $col, bool $isSingleAutoPk): string
    {
        if ($isSingleAutoPk) {
            // Required exact form; ignore declared type/nullability.
            return $this->quoteIdent($col->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = $this->quoteIdent($col->name) . ' ' . $this->columnTypeSql($col);
        $sql .= $col->nullable ? '' : ' NOT NULL';

        if ($col->default !== null || $col->defaultIsExpression) {
            $sql .= ' DEFAULT ' . $this->renderDefault($col);
        }

        if ($col->type === ColumnType::ENUM && ! empty($col->enumValues)) {
            $list = implode(',', array_map(fn ($v) => $this->quoteString((string) $v), $col->enumValues));
            $sql .= ' CHECK (' . $this->quoteIdent($col->name) . ' IN (' . $list . '))';
        }
        return $sql;
    }

    private function columnTypeSql(Column $col): string
    {
        return match ($col->type) {
            ColumnType::BOOL,
            ColumnType::TINYINT,
            ColumnType::SMALLINT,
            ColumnType::INTEGER,
            ColumnType::BIGINT     => 'INTEGER',
            ColumnType::DECIMAL    => 'NUMERIC',
            ColumnType::FLOAT,
            ColumnType::DOUBLE     => 'REAL',
            ColumnType::CHAR,
            ColumnType::VARCHAR,
            ColumnType::TEXT,
            ColumnType::MEDIUMTEXT,
            ColumnType::LONGTEXT,
            ColumnType::ENUM,
            ColumnType::JSON,
            ColumnType::UUID,
            ColumnType::DATE,
            ColumnType::TIME,
            ColumnType::DATETIME,
            ColumnType::TIMESTAMP  => 'TEXT',
            ColumnType::BINARY,
            ColumnType::VARBINARY,
            ColumnType::BLOB,
            ColumnType::MEDIUMBLOB,
            ColumnType::LONGBLOB   => 'BLOB',
        };
    }

    private function renderDefault(Column $col): string
    {
        if ($col->defaultIsExpression && $col->default !== null) {
            return $col->default;
        }
        if ($col->default === null) {
            return 'NULL';
        }
        if ($col->type === ColumnType::BOOL) {
            return in_array(strtolower($col->default), ['1', 'true', 't'], true) ? '1' : '0';
        }
        if ($col->type->isInteger() || in_array($col->type, [ColumnType::FLOAT, ColumnType::DOUBLE, ColumnType::DECIMAL], true)) {
            return is_numeric($col->default) ? $col->default : '0';
        }
        return $this->quoteString($col->default);
    }

    private function quoteValue(Column $col, mixed $value): string
    {
        if ($value === null) return 'NULL';

        if ($col->type === ColumnType::BOOL) {
            if (is_bool($value)) return $value ? '1' : '0';
            if (is_numeric($value)) return ((int) $value) ? '1' : '0';
            $s = strtolower((string) $value);
            return in_array($s, ['1', 'true', 't'], true) ? '1' : '0';
        }

        if ($col->type->isInteger()) {
            if (is_bool($value)) return $value ? '1' : '0';
            return (string) (is_numeric($value) ? $value : 0);
        }

        if (in_array($col->type, [ColumnType::FLOAT, ColumnType::DOUBLE, ColumnType::DECIMAL], true)) {
            return is_numeric($value) ? (string) $value : '0';
        }

        if ($col->type->isBinary()) {
            $bytes = is_resource($value) ? (stream_get_contents($value) ?: '') : (string) $value;
            // SQLite blob literal: X'DEADBEEF'
            return "X'" . bin2hex($bytes) . "'";
        }

        if ($col->type === ColumnType::JSON) {
            $s = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            return $this->quoteString((string) $s);
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }
        return $this->quoteString($value);
    }
}
