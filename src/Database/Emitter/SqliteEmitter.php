<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Database\Emitter;

use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Database\Schema\Column;
use Ramon\Backup\Database\Schema\ColumnType;
use Ramon\Backup\Database\Schema\Table;

/**
 * Emitter para SQLite 3.35+ (piso geral da extensão; o caminho de dados
 * daqui roda em versões bem mais antigas).
 *
 *   - Os tipos declarados são emitidos mesmo com tipagem dinâmica, para
 *     outras ferramentas entenderem o dump.
 *   - AUTOINCREMENT só vale em `INTEGER PRIMARY KEY`; um BIGINT
 *     auto-increment vira essa forma sem perder faixa, já que o SQLite
 *     guarda inteiros em 64 bits.
 *   - FKs ficam suspensas na carga por `PRAGMA foreign_keys = OFF`.
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

        // PK inteira única com auto-increment tem de virar INTEGER PRIMARY KEY
        // AUTOINCREMENT: o SQLite não aceita AUTOINCREMENT em outra forma.
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

        // O DROP sai antes, no dumper (ver SqlEmitter::emitDropTable).
        $sql = $create . $this->delimiter();

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
            // Forma exigida; ignora tipo e nulabilidade declarados. O NOT NULL
            // explícito evita que a releitura reporte `notnull = 0` para o alias do
            // rowid e gere PK anulável inválida no MySQL/PostgreSQL.
            return $this->quoteIdent($col->name) . ' INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT';
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
            // Literal blob: X'DEADBEEF'
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
