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

use Ramon\Backup\Database\Schema\Table;

interface SchemaIntrospector
{
    /**
     * Tabelas de usuário (sem views nem tabelas de sistema), em ordem estável
     * para o dump ser reproduzível entre ticks.
     *
     * @return list<string>
     */
    public function listTables(): array;

    public function describeTable(string $table): Table;

    /**
     * Avisos de tradução com perda para o admin (tipos sem equivalente,
     * colunas geradas). Cresce a cada `describeTable()`; quem exibe deduplica.
     *
     * @return list<string>
     */
    public function warnings(): array;
}
