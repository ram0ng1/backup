<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Database\Introspector;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\Schema\Column;
use Ramon\Backup\Database\Schema\ColumnType;
use Ramon\Backup\Database\Schema\ForeignKey;
use Ramon\Backup\Database\Schema\Index;
use Ramon\Backup\Database\Schema\Table;
use RuntimeException;

/**
 * Lê o schema de SQLite por PRAGMA e pelo `CREATE TABLE` original em
 * `sqlite_master`: só ele diz se a PK inteira foi declarada com
 * AUTOINCREMENT.
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
            // table_info traz pk como posição não nula; reconciliado abaixo.
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

            // Alias do ROWID: INTEGER PRIMARY KEY é a única forma de auto-increment.
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
                // PK nunca é nula no destino. O SQLite reporta `notnull = 0` para o alias
                // do rowid; confiar nisso geraria uma PK `NULL AUTO_INCREMENT` inválida
                // no MySQL ou no PostgreSQL.
                nullable: ((int) ($r['notnull'] ?? 0)) === 0 && ! $isPk,
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
     * Mapeia o tipo declarado por substring, a mesma heurística de afinidade
     * que o próprio SQLite usa.
     */
    private function mapAffinity(string $declared, string $table, string $column): ColumnType
    {
        // Coluna sem tipo declarado tem afinidade BLOB, mas TEXT é mais seguro
        // porque o PDO entrega strings. Sem aviso: é uma forma normal do SQLite.
        if ($declared === '') return ColumnType::TEXT;

        if (str_contains($declared, 'BIGINT')) return ColumnType::BIGINT;
        // `tinyint(1)` é como o Laravel declara booleano no SQLite.
        if (str_contains($declared, 'BOOL') || preg_match('/\ATINYINT\s*\(\s*1\s*\)/', $declared)) return ColumnType::BOOL;
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

        // Tipo desconhecido: os valores podem não seguir a semântica de TEXT.
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
            // Índices automáticos de PK e UNIQUE: o emitter os recria pela
            // definição da tabela.
            if ($origin === 'pk') continue;
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
