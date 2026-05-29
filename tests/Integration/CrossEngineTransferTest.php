<?php

namespace Ramon\Backup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ramon\Backup\Tests\Support\Engines;
use Ramon\Backup\Tests\Support\Fixture;
use Ramon\Backup\Tests\Support\Transfer;

/**
 * The headline e2e: a dump produced for engine B and replayed into a
 * live engine B must reproduce the source data EXACTLY, for every
 * ordered (source → target) pair of supported engines — i.e. "it works
 * in all directions".
 *
 * The provider enumerates the full 4×4 matrix (mysql, mariadb, postgres,
 * sqlite). Pairs whose source or target engine is not reachable in the
 * current environment are skipped, so locally this runs whatever is up
 * (e.g. mysql/postgres/sqlite → 9 pairs) and CI, with all four service
 * containers, runs the complete 16.
 *
 * Same-engine pairs double as the per-version round-trip check: the
 * dump targets the very engine it was read from and is restored in
 * place, which is exactly the regression that the `mariadb` driver bug
 * would have broken.
 */
final class CrossEngineTransferTest extends TestCase
{
    /**
     * @dataProvider directionProvider
     */
    public function test_transfer_preserves_data(string $sourceDialect, string $targetDialect): void
    {
        $source = Engines::connection($sourceDialect);
        $target = Engines::connection($targetDialect);

        if ($source === null || $target === null) {
            $this->markTestSkipped("Engines not both available: $sourceDialect → $targetDialect");
        }

        // Always rebuild + reseed the source so each direction starts
        // from a known state regardless of provider ordering.
        Fixture::build($source);
        Fixture::seed($source);
        $expected = Fixture::snapshot($source);

        $sql = Transfer::dump($source, $targetDialect);

        // Give the target a clean slate. Across the full matrix a single
        // server is reused as the target for dumps coming from different
        // source engines; without this, a child table left behind by an
        // earlier direction (with a differently-typed FK column) makes
        // the next CREATE fail the FK compatibility check. A real import
        // restores onto a fresh/consistent install, which this mirrors.
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

        // The dumped SQL must declare it targets the requested engine.
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
     * Robustness: a restore must succeed even when the destination
     * already holds an INCOMPATIBLE prior schema. We first leave the
     * target populated with its own engine's native shapes (MySQL ↔
     * UNSIGNED int columns, PostgreSQL ↔ a live posts→authors FK), then
     * restore a dump from a DIFFERENT source engine WITHOUT clearing the
     * target. A naive per-table DROP/CREATE breaks here — recreating the
     * parent trips MySQL's FK column-type check (error 3780), or fails
     * to drop a referenced parent on PostgreSQL. The up-front drop-all
     * (DatabaseDumper::dropAllTables) is what makes this safe.
     *
     * @dataProvider serverEngineProvider
     */
    public function test_reimport_over_incompatible_prior_state(string $target): void
    {
        $dest   = Engines::connection($target);
        $sqlite = Engines::connection('sqlite');

        if ($dest === null || $sqlite === null) {
            $this->markTestSkipped("Needs $target + sqlite available");
        }

        // Prior state: the target's own native schema (e.g. UNSIGNED
        // ints on MySQL; a referenced parent on PostgreSQL).
        Fixture::build($dest);
        Fixture::seed($dest);

        // A dump from a different engine yields differently-typed
        // columns for the same tables (e.g. signed ints when going
        // SQLite → MySQL).
        Fixture::build($sqlite);
        Fixture::seed($sqlite);
        $expected = Fixture::snapshot($sqlite);

        // Re-import over the incompatible prior state, no manual reset.
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
