<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\Support;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Database\DatabaseRestorer;
use Ramon\Backup\Database\Dialect;

/**
 * Roda o dump/restore de produção de ponta a ponta, como o
 * {@see \Ramon\Backup\Job\ExportJob} e o {@see \Ramon\Backup\Job\ImportJob}
 * fazem por tick, sem o empacotamento do arquivo e os chunks HTTP, que não
 * mudam se o SQL atravessa os motores.
 */
final class Transfer
{
    /**
     * Dump do banco de origem inteiro como SQL para `$targetDialect`. O banco
     * de teste só tem as tabelas da fixture.
     */
    public static function dump(Connection $source, string $targetDialect): string
    {
        $dumper = new DatabaseDumper($source, Dialect::parse($targetDialect));

        $sql = $dumper->preamble();
        // Todos os DROPs antes, depois CREATEs e dados, como no ExportJob.
        $sql .= $dumper->dropAllTables();
        foreach ($dumper->listTables() as $table) {
            $sql .= $dumper->dumpSchema($table);

            $offset = 0;
            $afterKey = null;
            while (true) {
                $batch = $dumper->dumpDataBatch($table, $offset, $afterKey);
                if ($batch['consumed'] === 0) break;
                $sql .= $batch['sql'];
                $offset += $batch['consumed'];
                $afterKey = $batch['after_key'];
            }
        }
        $sql .= $dumper->epilogue();

        return $sql;
    }

    /**
     * Aplica o SQL no destino com a mesma suspensão de FK que o import faz.
     */
    public static function restore(Connection $target, string $sql): void
    {
        DatabaseRestorer::disableForeignKeys($target);

        $restorer = new DatabaseRestorer($target);
        $restorer->feed($sql);
        $restorer->executeReady();
        $restorer->finish();

        DatabaseRestorer::enableForeignKeys($target);
    }
}
