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

use Ramon\Backup\Database\Schema\Table;

/**
 * Um emitter por dialeto alvo: recebe o schema neutro e as linhas e gera
 * o `CREATE TABLE` / `INSERT` do dialeto. Statements separados por
 * `\n-- @@END@@\n`, que é comentário em todos os dialetos.
 */
interface SqlEmitter
{
    /** Preparação da sessão (toggle de FK, encoding etc.). */
    public function preamble(): string;

    /** Desfaz os toggles de sessão do preamble(). */
    public function epilogue(): string;

    /**
     * `DROP TABLE IF EXISTS` na forma mais segura do dialeto (CASCADE no
     * PostgreSQL). O dumper emite todos antes de qualquer CREATE, para uma
     * filha que sobrou com FK de outro tipo não travar o CREATE do pai.
     */
    public function emitDropTable(string $name): string;

    public function emitSchema(Table $table): string;

    /**
     * `$rows` vem no formato nativo da conexão de origem; o quoting por tipo
     * fica com o emitter.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function emitInserts(Table $table, array $rows): string;

    /**
     * Statements por tabela depois da carga dos dados (FKs e sequências no
     * PostgreSQL; vazio nos outros).
     */
    public function emitPostDataFixups(Table $table): string;

    /** Dialeto gravado no meta do arquivo. */
    public function targetTag(): string;

    /**
     * Avisos de tradução com perda, ex.: índice FULLTEXT pulado no PG.
     *
     * @return list<string>
     */
    public function warnings(): array;
}
