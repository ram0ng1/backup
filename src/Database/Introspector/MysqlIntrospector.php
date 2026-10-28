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
use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Database\Schema\Column;
use Ramon\Backup\Database\Schema\ColumnType;
use Ramon\Backup\Database\Schema\ForeignKey;
use Ramon\Backup\Database\Schema\Index;
use Ramon\Backup\Database\Schema\Table;
use RuntimeException;

/**
 * Lê o schema de MySQL/MariaDB por `information_schema`, que funciona
 * mesmo onde hosts gerenciados restringem `SHOW CREATE TABLE`.
 *
 * Desde a 10.2.7 o MariaDB devolve COLUMN_DEFAULT como expressão SQL
 * (strings entre aspas, NULL como o texto `"NULL"`); `normaliseDefault()`
 * deixa as duas formas iguais.
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
                    NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_COMMENT,
                    GENERATION_EXPRESSION
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

            // Coluna gerada: os valores materializados são copiados, mas a expressão
            // só vale no MySQL/MariaDB e os emitters a descartam, então avisa o admin.
            // Detecta por GENERATION_EXPRESSION, não por EXTRA: no MySQL 8 um
            // `DEFAULT CURRENT_TIMESTAMP` aparece em EXTRA como `DEFAULT_GENERATED`.
            $generationExpr = trim((string) ($r['GENERATION_EXPRESSION'] ?? ''));
            if ($generationExpr !== '') {
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
     * Normaliza `COLUMN_DEFAULT` para (valor|null, éExpressão).
     *
     * MySQL ≤ 8.0 devolve NULL para "sem default" e o literal cru, sem aspas.
     * MariaDB ≥ 10.2.7 devolve o texto da expressão: NULL vira `"NULL"`,
     * strings vêm entre aspas simples (com `''` dobrado) e expressões como
     * CURRENT_TIMESTAMP vêm sem aspas.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function normaliseDefault(mixed $raw): array
    {
        if ($raw === null) return [null, false];

        $s = (string) $raw;

        // MariaDB: "sem default" chega como o texto `NULL`.
        if ($this->isMariaDb && $s === 'NULL') return [null, false];

        // String com aspas, só no MariaDB: tira uma camada e desfaz o `''`.
        if ($this->isMariaDb && strlen($s) >= 2 && $s[0] === "'" && substr($s, -1) === "'") {
            $inner = substr($s, 1, -1);
            return [str_replace("''", "'", $inner), false];
        }

        // Sem aspas no emitter: CURRENT_TIMESTAMP, não 'CURRENT_TIMESTAMP'. O
        // MariaDB escreve `current_timestamp()`, forma que o PostgreSQL e o
        // SQLite recusam; todas viram a palavra-chave do padrão SQL.
        $upper = strtoupper($s);
        if (preg_match('/\A(CURRENT_TIMESTAMP|NOW|LOCALTIMESTAMP)(\(\d*\))?\z/', $upper)) {
            return ['CURRENT_TIMESTAMP', true];
        }
        if (preg_match('/\A(CURRENT_DATE|CURDATE)(\(\))?\z/', $upper)) {
            return ['CURRENT_DATE', true];
        }
        if (preg_match('/\A(CURRENT_TIME|CURTIME)(\(\d*\))?\z/', $upper)) {
            return ['CURRENT_TIME', true];
        }

        // MariaDB também traz `current_timestamp()` e aritmética sem aspas.
        // Número é literal; o resto passa adiante como expressão.
        if ($this->isMariaDb && ! is_numeric($s)) {
            return [$s, true];
        }

        return [$s, false];
    }

    /**
     * DATA_TYPE / COLUMN_TYPE para o tipo neutro, mais os valores de ENUM.
     * Tipos que não dá para modelar por inteiro (SET, espaciais, BIT(>1),
     * desconhecidos) geram aviso: os dados viajam, a semântica não.
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
            // SET chega como string separada por vírgula; perde só a semântica.
            'set'        => $this->warnLossyType($table, $column, $dataType,
                            'multi-value SET semantics are dropped; values travel as a comma-joined string',
                            ColumnType::TEXT),
            // BIT(1) é o booleano do MySQL; BIT(>1) é binário e só sobrevive em BLOB.
            'bit'        => str_contains($columnType, '(1)')
                              ? ColumnType::BOOL
                              : $this->warnLossyType($table, $column, $columnType,
                                  'multi-bit BIT() has no native equivalent — values travel as raw bytes',
                                  ColumnType::BLOB),
            // Espaciais são WKB binário; como TEXT a validação UTF-8 do PG/SQLite
            // recusaria, então vão como BLOB.
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
            $enumValues = [];
            foreach (str_getcsv($m[1], ',', "'", '\\') as $v) {
                $enumValues[] = (string) $v;
            }
        }

        return [$type, $enumValues];
    }

    /**
     * Registra o aviso e devolve o tipo de fallback. O fallback é parâmetro
     * porque espaciais e BIT(>1) precisam de BLOB (TEXT falharia na
     * validação UTF-8 do PG).
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
