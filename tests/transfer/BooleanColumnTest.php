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
 * Booleano tem que chegar como booleano. O Flarum filtra com
 * `is_sticky and ...`, que o PostgreSQL recusa numa coluna inteira; no
 * SQLite o Laravel declara booleano como `tinyint(1)`.
 */
final class BooleanColumnTest extends TestCase
{
    #[DataProvider('directionProvider')]
    public function test_booleano_continua_booleano(string $sourceDialect, string $targetDialect): void
    {
        $source = Engines::connection($sourceDialect);
        $target = Engines::connection($targetDialect);
        if ($source === null || $target === null) {
            $this->markTestSkipped("Engines not both available: $sourceDialect → $targetDialect");
        }

        Fixture::build($source);
        Fixture::seed($source);
        $sql = Transfer::dump($source, $targetDialect);
        Fixture::reset($target);
        Transfer::restore($target, $sql);

        $active = $target->selectOne('SELECT COUNT(*) AS c FROM bkp_authors WHERE active AND id > 0');

        $this->assertSame(2, (int) ((array) $active)['c']);
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
