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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Tests\Support\Engines;
use Ramon\Backup\Tests\Support\Fixture;
use Ramon\Backup\Tests\Support\Transfer;

/**
 * Fração de segundo da origem não pode mudar o segundo no destino.
 *
 * O PostgreSQL guarda microssegundos (o `post_likes.created_at` do Flarum
 * vem assim) e o SQLite guarda o texto que recebeu. A coluna no MySQL sai
 * sem fração, e o MySQL arredonda: 12.833971 virava 13. O MariaDB corta.
 */
final class FractionalSecondsTest extends TestCase
{
    #[DataProvider('directionProvider')]
    public function test_fracao_nao_muda_o_segundo(string $sourceDialect, string $targetDialect): void
    {
        $source = Engines::connection($sourceDialect);
        $target = Engines::connection($targetDialect);
        if ($source === null || $target === null) {
            $this->markTestSkipped("Engines not both available: $sourceDialect → $targetDialect");
        }

        Fixture::build($source);
        Fixture::seed($source);
        if ($sourceDialect === 'postgres') {
            $source->statement('ALTER TABLE bkp_posts ALTER COLUMN created_at TYPE timestamp(6) without time zone');
        }
        $source->table('bkp_posts')->where('id', 1)->update(['created_at' => '2024-02-01 10:00:00.833971']);

        $sql = Transfer::dump($source, $targetDialect);
        Fixture::reset($target);
        Transfer::restore($target, $sql);

        $row = (array) $target->selectOne('SELECT created_at FROM bkp_posts WHERE id = 1');

        $this->assertSame('2024-02-01 10:00:00', substr((string) $row['created_at'], 0, 19));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function directionProvider(): iterable
    {
        foreach (['postgres', 'sqlite'] as $src) {
            foreach (Engines::ALL as $dst) {
                yield "$src -> $dst" => [$src, $dst];
            }
        }
    }
}
