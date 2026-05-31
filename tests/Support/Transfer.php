<?php

namespace Ramon\Backup\Tests\Support;

use Illuminate\Database\Connection;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Database\DatabaseRestorer;
use Ramon\Backup\Database\Dialect;

/**
 * Thin test-only driver that runs the production dump/restore engine end
 * to end, mirroring exactly what {@see \Ramon\Backup\Job\ExportJob} and
 * {@see \Ramon\Backup\Job\ImportJob} do per tick — minus the archive
 * packaging and HTTP chunking, which are irrelevant to whether the SQL
 * itself transfers correctly between engines.
 */
final class Transfer
{
    /**
     * Dump the whole source database as portable SQL targeting
     * `$targetDialect`. The source connection's dedicated test DB only
     * holds the fixture tables, so the dump is naturally scoped.
     */
    public static function dump(Connection $source, string $targetDialect): string
    {
        $dumper = new DatabaseDumper($source, Dialect::parse($targetDialect));

        $sql = $dumper->preamble();
        // All DROPs first, then all CREATEs+data — mirrors ExportJob.
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
     * Replay dumped SQL into the target connection, applying the same
     * FK-suspension dance the import job performs around the load.
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
