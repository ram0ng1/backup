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
 * Um dump gerado para o motor B e aplicado num motor B real tem que
 * reproduzir a origem EXATAMENTE, para todo par ordenado (origem →
 * destino) de motores suportados.
 *
 * O provider monta a matriz 4×4 (mysql, mariadb, postgres, sqlite). Pares
 * com motor fora do ar são pulados: localmente roda o que estiver no ar e
 * a CI, com os quatro serviços, roda os 16.
 *
 * Os pares do mesmo motor servem de ida e volta por versão: foi esse o
 * caminho que o bug do driver `mariadb` quebrava.
 */
final class CrossEngineTransferTest extends TestCase
{
    #[DataProvider('directionProvider')]
    public function test_transfer_preserves_data(string $sourceDialect, string $targetDialect): void
    {
        $source = Engines::connection($sourceDialect);
        $target = Engines::connection($targetDialect);

        if ($source === null || $target === null) {
            $this->markTestSkipped("Engines not both available: $sourceDialect → $targetDialect");
        }

        // Recria e popula a origem a cada direção, para não depender da ordem do
        // provider.
        Fixture::build($source);
        Fixture::seed($source);
        $expected = Fixture::snapshot($source);

        $sql = Transfer::dump($source, $targetDialect);

        // Destino limpo: na matriz o mesmo servidor recebe dumps de motores
        // diferentes, e uma tabela filha deixada por outra direção (com FK de
        // outro tipo) faz o próximo CREATE falhar na checagem de FK. Um import
        // real também cai numa instalação consistente.
        Fixture::reset($target);

        Transfer::restore($target, $sql);

        $actual = Fixture::snapshot($target);

        $this->assertSame(
            $expected['authors'],
            $actual['authors'],
            "Authors mismatch transferring $sourceDialect → $targetDialect"
        );
        $this->assertSame(
            $expected['posts'],
            $actual['posts'],
            "Posts mismatch transferring $sourceDialect → $targetDialect"
        );

        // O SQL gerado precisa declarar o motor de destino pedido.
        $this->assertNotSame('', $sql);
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

    /**
     * O restore tem que funcionar mesmo com um schema anterior INCOMPATÍVEL
     * no destino: tipos nativos do próprio motor (INT UNSIGNED no MySQL, FK
     * posts→authors viva no PostgreSQL) e um dump de OUTRO motor por cima,
     * sem limpar. DROP/CREATE tabela a tabela quebra aqui (erro 3780 do MySQL
     * na checagem de tipo da FK, ou pai referenciado que o PostgreSQL não
     * deixa apagar); o drop-all antecipado (DatabaseDumper::dropAllTables)
     * é o que resolve.
     */
    #[DataProvider('serverEngineProvider')]
    public function test_reimport_over_incompatible_prior_state(string $target): void
    {
        $dest   = Engines::connection($target);
        $sqlite = Engines::connection('sqlite');

        if ($dest === null || $sqlite === null) {
            $this->markTestSkipped("Needs $target + sqlite available");
        }

        // Estado anterior: o schema nativo do próprio destino.
        Fixture::build($dest);
        Fixture::seed($dest);

        // Um dump de outro motor traz colunas de outro tipo para as mesmas
        // tabelas (ex.: int com sinal indo de SQLite para MySQL).
        Fixture::build($sqlite);
        Fixture::seed($sqlite);
        $expected = Fixture::snapshot($sqlite);

        // Reimporta por cima do estado incompatível, sem reset manual.
        Transfer::restore($dest, Transfer::dump($sqlite, $target));

        $actual = Fixture::snapshot($dest);
        $this->assertSame($expected['authors'], $actual['authors'], "Authors mismatch re-importing into $target");
        $this->assertSame($expected['posts'], $actual['posts'], "Posts mismatch re-importing into $target");
    }

    /** @return iterable<string, array{0: string}> */
    public static function serverEngineProvider(): iterable
    {
        yield 'mysql'    => ['mysql'];
        yield 'mariadb'  => ['mariadb'];
        yield 'postgres' => ['postgres'];
    }
}
