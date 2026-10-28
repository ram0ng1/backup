<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
 * Dump de banco entre engines, retomável por tick.
 *
 * Um `SchemaIntrospector` do banco de origem gera o modelo neutro
 * (`Schema\Table`) e o `SqlEmitter` do dialeto escolhido pelo admin o
 * transforma em SQL. Os statements são separados por `\n-- @@END@@\n`,
 * que o `DatabaseRestorer` usa no lugar de `;` (literais e blocos `$$` do
 * PostgreSQL podem conter ponto e vírgula). Como o separador é comentário
 * em todos os engines, o dump também roda nos clientes de linha de comando.
 */
class DatabaseDumper
{
    public const STATEMENT_DELIMITER = "\n-- @@END@@\n";

    public const PHASE_SCHEMA = 'schema';
    public const PHASE_DATA   = 'data';
    public const PHASE_DONE   = 'done';

    /**
     * Linhas por SELECT em tabelas que podem ter valores grandes (TEXT/BLOB/
     * JSON ou VARCHAR/BINARY largos): pequeno para um lote não segurar
     * dezenas de MB no PHP.
     */
    private const ROWS_PER_QUERY = 200;

    /**
     * Linhas por SELECT em tabelas só com escalares curtos, como pivôs do tipo
     * `post_likes`. Corta as idas ao banco em ~25×; seguro em memória porque
     * cada linha é mínima, e o keyset mantém barato buscar lotes profundos.
     */
    private const ROWS_PER_QUERY_BULK = 5000;

    /** Colunas string/binárias acima deste tamanho contam como "grandes". */
    private const WIDE_STRING_THRESHOLD = 1024;

    private SchemaIntrospector $introspector;
    private SqlEmitter $emitter;

    /** Cache: tabela → modelo neutro, para não consultar o catálogo de novo. */
    private array $describedCache = [];

    public function __construct(
        protected Connection $db,
        ?Dialect $target = null,
    ) {
        $source = Dialect::detect($db);
        $this->introspector = IntrospectorFactory::for($db, $source);
        $this->emitter      = EmitterFactory::for($target ?? $source);
    }

    /** Dialeto alvo do SQL gerado, gravado no meta do arquivo. */
    public function targetTag(): string
    {
        return $this->emitter->targetTag();
    }

    /**
     * Avisos de tradução com perda (do introspector e do emitter) desta
     * instância. O ExportJob junta os de todos os ticks no estado do job.
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
     * Em ordem estável, para o dump ser reproduzível e retomável.
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
     * Todos os `DROP TABLE IF EXISTS` num bloco só, antes de qualquer CREATE.
     * Assim uma tabela filha que sobrou de outro dump não derruba o CREATE do
     * pai na checagem de tipo de FK do MySQL, e no PostgreSQL o CASCADE
     * dispensa ordenar filhos antes de pais.
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
        // Fixups por tabela (setval de sequência e FKs no PostgreSQL) vão logo
        // antes do epílogo, com todas as linhas já carregadas. O dumper é recriado
        // a cada tick, então as tabelas são descritas de novo em vez de confiar no
        // cache. Só o emitter sabe se há algo a fazer; ele devolve '' se não houver.
        $tail = '';
        foreach ($this->introspector->listTables() as $name) {
            $tail .= $this->emitter->emitPostDataFixups($this->describe($name));
        }
        return $tail . $this->emitter->epilogue();
    }

    public function dumpSchema(string $table): string
    {
        $this->assertSafeIdent($table);
        $described = $this->describe($table);
        return $this->emitter->emitSchema($described);
    }

    /**
     * Próximo lote de linhas de `$table`: SQL, linhas consumidas e a chave da
     * última linha, para o próximo tick continuar dali.
     *
     * Com primary key, pagina por keyset na forma EXPANDIDA
     * `a > ? OR (a = ? AND b > ?)` (ver keysetPredicate). A tupla
     * `(a, b) > (?, ?)` vira varredura cheia do índice no MySQL 8 e fica O(n²)
     * em chaves profundas; a forma expandida faz range scan e funciona em
     * MySQL, MariaDB, PostgreSQL e SQLite. Sem primary key, cai no `$offset`.
     *
     * SQL vazio com `consumed == 0` indica tabela esgotada.
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

    /** Quoting do SELECT: segue o engine de ORIGEM, não o alvo. */
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
     * Identificadores vêm do catálogo, então dado normal nunca cai aqui. É
     * barreira contra catálogo corrompido ou um caminho futuro que repasse
     * input da requisição.
     */
    private function assertSafeIdent(string $ident): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $ident)) {
            throw new RuntimeException('Invalid input');
        }
    }

    /**
     * Para o progresso "linha X de Y". Chamado uma vez por tabela, no primeiro
     * tick, então o `COUNT(*)` não é pago a cada lote.
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
     * Predicado de keyset na forma lexicográfica expandida:
     *
     *   (a > ?)
     *   OR (a = ? AND b > ?)
     *   OR (a = ? AND b = ? AND c > ?)
     *
     * Cada cláusula só usa igualdades nas colunas anteriores e `>` na atual,
     * o que o otimizador resolve como range scan. Os bindings entram em
     * `$bindings` na MESMA ordem dos `?`.
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
     * Qualquer coluna que possa ser grande (TEXT/BLOB/JSON, ou VARCHAR/BINARY
     * acima de WIDE_STRING_THRESHOLD) força o lote pequeno.
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
