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

use Ramon\Backup\Database\DatabaseDumper;

/**
 * Base comum dos emitters: quoting de identificadores e a decisão de como
 * renderizar um default literal. Cada dialeto sobrescreve valores, tipos
 * e DDL.
 */
abstract class AbstractEmitter implements SqlEmitter
{
    /** Caractere de quoting de identificadores no dialeto. */
    abstract protected function identQuote(): string;

    public function emitPostDataFixups(\Ramon\Backup\Database\Schema\Table $table): string
    {
        return '';
    }

    public function emitDropTable(string $name): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdent($name)
             . $this->dropTableSuffix() . $this->delimiter();
    }

    /**
     * Sufixo do DROP TABLE. Vazio em MySQL/MariaDB/SQLite, onde o restore
     * suspende as FKs; o PostgreSQL usa ` CASCADE` para derrubar um pai
     * referenciado sem erro.
     */
    protected function dropTableSuffix(): string
    {
        return '';
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return [];
    }

    /**
     * Escapa dobrando o caractere de quoting: padrão SQL para `"` e o escape
     * documentado do MySQL para `` ` ``. Identificadores vêm do catálogo, mas
     * NUL e bytes de controle são recusados para um catálogo malformado não
     * injetar um terminador de statement no dump.
     */
    protected function quoteIdent(string $ident): string
    {
        if ($ident === '' || preg_match('/[\x00-\x1F\x7F]/', $ident)) {
            throw new \InvalidArgumentException('Unsafe identifier rejected.');
        }
        $q = $this->identQuote();
        return $q . str_replace($q, $q . $q, $ident) . $q;
    }

    protected function delimiter(): string
    {
        return DatabaseDumper::STATEMENT_DELIMITER;
    }

    /**
     * Literal SQL padrão, com aspas simples dobradas. Subclasses tratam
     * binário, JSON etc.
     */
    protected function quoteString(string $value): string
    {
        $parts = $this->splitAtDelimiter($value);
        if (count($parts) === 1) {
            return $this->quoteLiteral($value);
        }

        return $this->joinLiterals(array_map(fn (string $part) => $this->quoteLiteral($part), $parts));
    }

    protected function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Concatena literais já quotados. O padrão (`||` entre parênteses) vale
     * em VALUES e em DEFAULT no SQLite.
     *
     * @param list<string> $literals
     */
    protected function joinLiterals(array $literals): string
    {
        return '(' . implode(' || ', $literals) . ')';
    }

    /**
     * O restorer separa statements pela linha `-- @@END@@`, sem olhar aspas.
     * Um valor que contém esse texto (um post com bloco de código, o motivo
     * de uma denúncia) quebraria o INSERT no meio do literal; partindo o
     * literal entre `@@` e `END@@`, o separador nunca aparece contíguo.
     *
     * @return list<string>
     */
    protected function splitAtDelimiter(string $value): array
    {
        if (! str_contains($value, '@@END@@')) {
            return [$value];
        }

        return preg_split('/(?<=@@)(?=END@@)/', $value) ?: [$value];
    }
}
