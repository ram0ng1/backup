<?php

namespace Ramon\Backup\Database\Emitter;

use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Database\Schema\Column;
use Ramon\Backup\Database\Schema\ColumnType;
use Ramon\Backup\Database\Schema\Table;

/**
 * Emitter for MySQL (5.7+, 8.0+) and MariaDB (10.3+). The two share
 * almost all syntax — the differences (JSON storage, sequence support,
 * IGNORE INDEX hints) don't matter for our schema/data shape, so a
 * single emitter handles both.
 */
class MysqlEmitter extends AbstractEmitter
{
    public function __construct(
        protected Dialect $dialect = Dialect::MYSQL,
    ) {
    }

    protected function identQuote(): string
    {
        return '`';
    }

    public function targetTag(): string
    {
        return $this->dialect->value;
    }

    public function preamble(): string
    {
        $now = gmdate('Y-m-d H:i:s');
        return implode($this->delimiter(), [
            "-- Flarum backup — database dump (target: {$this->dialect->value})",
            "-- Generated at $now UTC",
            "SET NAMES utf8mb4",
            "SET FOREIGN_KEY_CHECKS = 0",
            "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'",
        ]) . $this->delimiter();
    }

    public function epilogue(): string
    {
        return 'SET FOREIGN_KEY_CHECKS = 1' . $this->delimiter();
    }

    public function emitSchema(Table $table): string
    {
        $name = $this->quoteIdent($table->name);

        $lines = [];
        foreach ($table->columns as $col) {
            $lines[] = '  ' . $this->columnDdl($col);
        }
        if (! empty($table->primaryKey)) {
            $lines[] = '  PRIMARY KEY (' . $this->columnList($table->primaryKey) . ')';
        }
        foreach ($table->indexes as $idx) {
            if ($idx->primary) continue;
            $kind = $idx->kind ? strtoupper($idx->kind) . ' ' : '';
            $unique = $idx->unique && ! $idx->kind ? 'UNIQUE ' : '';
            $lines[] = '  ' . $unique . $kind . 'KEY ' . $this->quoteIdent($idx->name)
                . ' (' . $this->columnList($idx->columns) . ')';
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
        // Single-line collapse so the dump's per-statement delimiter
        // (a comment) is the only newline boundary the restorer sees.
        $create = "CREATE TABLE $name (\n$body\n) DEFAULT CHARSET=utf8mb4";
        $create = preg_replace('/\s+/', ' ', $create);

        return 'DROP TABLE IF EXISTS ' . $name . $this->delimiter()
             . $create . $this->delimiter();
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

    private function columnDdl(Column $col): string
    {
        $sql = $this->quoteIdent($col->name) . ' ' . $this->columnTypeSql($col);
        if ($col->unsigned && $col->type->isInteger()) {
            $sql .= ' UNSIGNED';
        }
        $sql .= $col->nullable ? ' NULL' : ' NOT NULL';

        if ($col->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        } elseif ($col->default !== null || $col->defaultIsExpression) {
            // MySQL/MariaDB refuse DEFAULT on TEXT/BLOB/JSON/GEOMETRY
            // ("BLOB, TEXT, GEOMETRY or JSON column 'x' can't have a
            // default value"). MariaDB 10.2.1+ permits an expression
            // default, but plain literal defaults are still rejected
            // and the constraint is moot for a backup target. Drop
            // the default silently — the destination column is still
            // nullable / non-null per the source, just without a
            // server-side filler.
            if ($this->columnDefaultDisallowed($col->type)) {
                // intentional no-op
            } else {
                $sql .= ' DEFAULT ' . $this->renderDefault($col);
            }
        } elseif ($col->nullable) {
            // No-op; NULL default implicit
        }

        if ($col->onUpdate) {
            $sql .= ' ON UPDATE ' . $col->onUpdate;
        }
        if ($col->comment !== null && $col->comment !== '') {
            $sql .= ' COMMENT ' . $this->quoteString($col->comment);
        }
        return $sql;
    }

    private function columnTypeSql(Column $col): string
    {
        return match ($col->type) {
            ColumnType::BOOL       => 'TINYINT(1)',
            ColumnType::TINYINT    => 'TINYINT',
            ColumnType::SMALLINT   => 'SMALLINT',
            ColumnType::INTEGER    => 'INT',
            ColumnType::BIGINT     => 'BIGINT',
            ColumnType::DECIMAL    => 'DECIMAL(' . ($col->precision ?? 10) . ',' . ($col->scale ?? 0) . ')',
            ColumnType::FLOAT      => 'FLOAT',
            ColumnType::DOUBLE     => 'DOUBLE',
            ColumnType::CHAR       => 'CHAR(' . ($col->length ?? 1) . ')',
            ColumnType::VARCHAR    => 'VARCHAR(' . ($col->length ?? 255) . ')',
            ColumnType::TEXT       => 'TEXT',
            ColumnType::MEDIUMTEXT => 'MEDIUMTEXT',
            ColumnType::LONGTEXT   => 'LONGTEXT',
            ColumnType::BINARY     => 'BINARY(' . ($col->length ?? 1) . ')',
            ColumnType::VARBINARY  => 'VARBINARY(' . ($col->length ?? 255) . ')',
            ColumnType::BLOB       => 'BLOB',
            ColumnType::MEDIUMBLOB => 'MEDIUMBLOB',
            ColumnType::LONGBLOB   => 'LONGBLOB',
            ColumnType::DATE       => 'DATE',
            ColumnType::TIME       => 'TIME',
            ColumnType::DATETIME   => 'DATETIME',
            ColumnType::TIMESTAMP  => 'TIMESTAMP',
            ColumnType::JSON       => $this->dialect === Dialect::MYSQL ? 'JSON' : 'LONGTEXT',
            ColumnType::ENUM       => 'ENUM(' . implode(',', array_map(
                                          fn ($v) => $this->quoteString((string) $v),
                                          $col->enumValues ?? []
                                      )) . ')',
            ColumnType::UUID       => 'CHAR(36)',
        };
    }

    /**
     * MySQL family rejects literal DEFAULT values on the LOB-style
     * types — TEXT (all sizes), BLOB (all sizes), JSON, and the
     * spatial types. Centralised here so column DDL and any future
     * ALTER TABLE path stay consistent.
     */
    private function columnDefaultDisallowed(ColumnType $t): bool
    {
        return in_array($t, [
            ColumnType::TEXT, ColumnType::MEDIUMTEXT, ColumnType::LONGTEXT,
            ColumnType::BLOB, ColumnType::MEDIUMBLOB, ColumnType::LONGBLOB,
            ColumnType::JSON,
        ], true);
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
        if ($col->type->isInteger() || $col->type === ColumnType::FLOAT
            || $col->type === ColumnType::DOUBLE || $col->type === ColumnType::DECIMAL) {
            return is_numeric($col->default) ? $col->default : '0';
        }
        return $this->quoteString($col->default);
    }

    /**
     * Type-aware quoting for one value. Booleans go to 1/0, integers
     * stay bare, JSON travels as a quoted string (MySQL parses it on
     * insert), binary uses 0xHEX so we never wrestle with charset
     * round-trips.
     */
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
            $bytes = is_string($value) ? $value : (string) $value;
            // Resources from PG `bytea` arrive as streams.
            if (is_resource($value)) {
                $bytes = stream_get_contents($value) ?: '';
            }
            return $bytes === '' ? "''" : '0x' . bin2hex($bytes);
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        // String containing NUL or non-UTF-8 bytes: travel as 0xHEX so
        // it survives any charset, even though the column is textual.
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            return '0x' . bin2hex($value);
        }
        return $this->quoteString($value);
    }
}
