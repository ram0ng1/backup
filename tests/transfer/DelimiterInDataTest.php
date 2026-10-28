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
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Tests\Support\Engines;
use Ramon\Backup\Tests\Support\Fixture;
use Ramon\Backup\Tests\Support\Transfer;

/**
 * O separador de statements do dump é uma linha de comentário. Qualquer
 * usuário consegue gravar essa linha num post (bloco de código), no motivo
 * de uma denúncia ou na bio; o dump tem que sobreviver a isso.
 */
final class DelimiterInDataTest extends TestCase
{
    #[DataProvider('directionProvider')]
    public function test_valor_com_o_separador_volta_igual(string $sourceDialect, string $targetDialect): void
    {
        $source = Engines::connection($sourceDialect);
        $target = Engines::connection($targetDialect);

        if ($source === null || $target === null) {
            $this->markTestSkipped("Engines not both available: $sourceDialect → $targetDialect");
        }

        Fixture::build($source);
        Fixture::seed($source);

        $hostile = "```\nantes".DatabaseDumper::STATEMENT_DELIMITER."DROP TABLE bkp_authors".DatabaseDumper::STATEMENT_DELIMITER."depois\n```";
        $source->table('bkp_posts')->insert([
            'id' => 4, 'author_id' => 2, 'title' => '-- @@END@@', 'body' => $hostile, 'created_at' => null,
        ]);
        $source->table('bkp_authors')->where('id', 3)->update(['name' => "@@END@@\n-- @@END@@"]);

        $expected = Fixture::snapshot($source);

        $sql = Transfer::dump($source, $targetDialect);

        Fixture::reset($target);
        Transfer::restore($target, $sql);

        $this->assertSame($expected, Fixture::snapshot($target));
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
