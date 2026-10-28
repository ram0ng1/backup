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
 * Emitter para MySQL (5.7+, 8.0+) e MariaDB (10.3+). As diferenças entre
 * os dois não afetam o schema e os dados que geramos.
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

    /**
     * Sem NO_BACKSLASH_ESCAPES (de propósito, para o .sql carregar em qualquer
     * cliente no modo padrão), a barra invertida é escape no MySQL/MariaDB e
     * precisa ser dobrada; senão `C:\path` perde a barra no restore.
     * PostgreSQL e SQLite aceitam o literal como está.
     */
    protected function quoteLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    /**
     * Literais lado a lado viram um só no MySQL/MariaDB (`'a' 'b'` = `'ab'`),
     * em qualquer contexto: VALUES, DEFAULT, COMMENT, ENUM.
     *
     * @param list<string> $literals
     */
    protected function joinLiterals(array $literals): string
    {
        return implode(' ', $literals);
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
        // Uma linha só: o delimitador (um comentário) é a única quebra que o restorer vê.
        $create = "CREATE TABLE $name (\n$body\n) DEFAULT CHARSET=utf8mb4";
        $create = preg_replace('/\s+/', ' ', $create);

        // O DROP de todas as tabelas sai antes, no dumper (ver SqlEmitter::emitDropTable).
        return $create . $this->delimiter();
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
        // AUTO_INCREMENT já é NOT NULL e o MySQL recusa PK anulável; força NOT NULL
        // mesmo se o modelo disser o contrário (ex.: PK INTEGER vinda do SQLite).
        $sql .= ($col->nullable && ! $col->autoIncrement) ? ' NULL' : ' NOT NULL';

        if ($col->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        } elseif ($col->default !== null || $col->defaultIsExpression) {
            // MySQL/MariaDB recusam DEFAULT literal em TEXT/BLOB/JSON/GEOMETRY. O
            // default é descartado; a nulabilidade da coluna continua a da origem.
            if ($this->columnDefaultDisallowed($col->type)) {
                // sem DEFAULT, ver acima
            } else {
                $sql .= ' DEFAULT ' . $this->renderDefault($col);
            }
        } elseif ($col->nullable) {
            // NULL já é o default implícito
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
     * Tipos que a família MySQL não aceita com DEFAULT literal: TEXT e BLOB
     * (todos os tamanhos), JSON e espaciais.
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
     * Booleano vira 1/0, inteiro vai sem aspas, JSON como string e binário
     * como 0xHEX, para não depender de conversão de charset.
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
            // `bytea` do PG chega como stream.
            if (is_resource($value)) {
                $bytes = stream_get_contents($value) ?: '';
            }
            return $bytes === '' ? "''" : '0x' . bin2hex($bytes);
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        // NUL ou bytes fora de UTF-8 vão como 0xHEX, mesmo em coluna de texto.
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            return '0x' . bin2hex($value);
        }
        return $this->quoteString($value);
    }
}
