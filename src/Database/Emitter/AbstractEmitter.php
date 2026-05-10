<?php

namespace Ramon\Backup\Database\Emitter;

use Ramon\Backup\Database\DatabaseDumper;

/**
 * Shared bits between the three concrete emitters: identifier quoting
 * helpers (one quote char), the "render a literal default" decision
 * tree, and a couple of constants. Concrete emitters override the
 * dialect-specific rendering of values, types, and DDL.
 */
abstract class AbstractEmitter implements SqlEmitter
{
    /** The character used to quote identifiers in this dialect. */
    abstract protected function identQuote(): string;

    public function emitPostDataFixups(\Ramon\Backup\Database\Schema\Table $table): string
    {
        return '';
    }

    /**
     * Default: no notes. Concrete emitters that record translation
     * notes (e.g. PG skipping FULLTEXT or oversized btree indexes)
     * override this.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return [];
    }

    /**
     * Quote an identifier (table name, column name, index name…). The
     * built-in escape for both backticks and double-quotes is to
     * double the quote character — that's the SQL standard for `"` and
     * MySQL's documented escape for `` ` ``.
     */
    protected function quoteIdent(string $ident): string
    {
        $q = $this->identQuote();
        return $q . str_replace($q, $q . $q, $ident) . $q;
    }

    protected function delimiter(): string
    {
        return DatabaseDumper::STATEMENT_DELIMITER;
    }

    /**
     * Standard SQL string literal: single quotes around the value,
     * doubling any embedded single quotes. Engine-specific subclasses
     * may wrap or replace this for binary, JSON, etc.
     */
    protected function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
