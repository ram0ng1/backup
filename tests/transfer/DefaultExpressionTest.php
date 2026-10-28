<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\transfer;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Tests\Support\Engines;
use Ramon\Backup\Tests\Support\Transfer;

/**
 * Colunas com `DEFAULT CURRENT_TIMESTAMP` (como `discussion_tag.created_at`
 * do Flarum). O MariaDB informa esse default como `current_timestamp()`,
 * forma que o PostgreSQL e o SQLite recusam.
 */
final class DefaultExpressionTest extends TestCase
{
    #[DataProvider('directionProvider')]
    public function test_default_current_timestamp_atravessa_motores(string $sourceDialect, string $targetDialect): void
    {
        $source = Engines::connection($sourceDialect);
        $target = Engines::connection($targetDialect);
        if ($source === null || $target === null) {
            $this->markTestSkipped("Engines not both available: $sourceDialect → $targetDialect");
        }

        $source->getSchemaBuilder()->dropAllTables();
        $source->getSchemaBuilder()->create('bkp_stamped', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamp('created_at')->nullable()->useCurrent();
        });
        $source->table('bkp_stamped')->insert(['id' => 1, 'name' => 'a', 'created_at' => '2026-01-02 03:04:05']);

        $sql = Transfer::dump($source, $targetDialect);
        $target->getSchemaBuilder()->dropAllTables();
        Transfer::restore($target, $sql);

        $target->table('bkp_stamped')->insert(['id' => 2, 'name' => 'b']);
        $rows = $target->table('bkp_stamped')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertSame('a', $rows[0]['name']);
        $this->assertSame('2026-01-02 03:04:05', date('Y-m-d H:i:s', strtotime((string) $rows[0]['created_at'])));
        $this->assertNotNull($rows[1]['created_at'], 'o default não foi recriado no destino');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function directionProvider(): iterable
    {
        foreach (Engines::ALL as $src) {
            foreach (Engines::ALL as $dst) {
                yield "$src -> $dst" => [$src, $dst];
            }
        }
    }
}
