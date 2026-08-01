<?php

namespace Ramon\Backup\Tests\Integration;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Extensions\Inventory;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;
use Ramon\Backup\Tests\Support\Fixture;

/**
 * The most complete e2e: for EVERY ordered (source → target) engine pair
 * it runs the real, full pipeline — `ExportJob` packs the source into an
 * actual `.flarum` archive, then `ImportJob` unpacks and restores that
 * archive into the target — and verifies the data survived. This is the
 * exact path the `backup:export` / `backup:import` CLI commands and the
 * admin UI drive, so it answers "does restore/import work in all
 * directions?" (mysql → postgres, postgres → mysql, …) end to end, not
 * just at the SQL-dump layer.
 *
 * Pairs whose source or target engine is unreachable are skipped, so
 * locally this runs the available engines (e.g. 9 of 16) and CI runs all
 * 16 with the four service containers up.
 */
final class FullPipelineTransferTest extends TestCase
{
    private string $workdir;
    private Paths $paths;
    private StoragePaths $storagePaths;
    private Config $config;

    protected function setUp(): void
    {
        // Models (the Backup row ExportJob writes on finalize) must
        // resolve through the test connections.
        Engines::bootEloquent();

        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_full_'.bin2hex(random_bytes(4));
        foreach (['', '/storage', '/public', '/public/assets'] as $sub) {
            @mkdir($this->workdir.$sub, 0777, true);
        }

        $this->paths = new Paths([
            'base'    => $this->workdir,
            'public'  => $this->workdir.DIRECTORY_SEPARATOR.'public',
            'storage' => $this->workdir.DIRECTORY_SEPARATOR.'storage',
        ]);
        $this->storagePaths = new StoragePaths($this->paths);
        $this->config = new Config(['url' => 'http://localhost']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->rrmdir($this->workdir);
    }

    /**
     * @dataProvider directionProvider
     */
    public function test_full_pipeline_transfer(string $sourceDialect, string $targetDialect): void
    {
        $source = Engines::connection($sourceDialect);
        $target = Engines::connection($targetDialect);

        if ($source === null || $target === null) {
            $this->markTestSkipped("Engines not both available: $sourceDialect → $targetDialect");
        }

        // Source holds the fixture we want to move plus the Backup
        // registry table (export finalize writes a row to it). Fixture
        // wipes the whole schema first, so the backups table is created
        // AFTER it.
        Fixture::build($source);
        Fixture::seed($source);
        $this->buildBackupsTable($source);
        $expected = Fixture::snapshot($source);

        // 1) Export the source into a real .flarum archive, targeting the
        //    destination engine. ExportJob's finalize hits the Backup
        //    model, so point Eloquent's default at the source first.
        Engines::setDefaultConnection($sourceDialect);
        $archive = $this->runExport($source, $targetDialect);
        $this->assertFileExists($archive, "Export ($sourceDialect → $targetDialect) produced no archive");

        // 2) Import that archive into the destination via the real
        //    ImportJob (inspect → extract → restore → rewrite → finalize).
        $this->runImport($target, $archive);

        // 3) The destination must now hold exactly the source's data.
        $actual = Fixture::snapshot($target);
        $this->assertSame($expected['authors'], $actual['authors'], "Authors mismatch $sourceDialect → $targetDialect");
        $this->assertSame($expected['posts'], $actual['posts'], "Posts mismatch $sourceDialect → $targetDialect");
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

    private function runExport(Connection $source, string $targetDialect): string
    {
        $job = new ExportJob(
            $this->storagePaths,
            $this->paths,
            $source,
            $this->cipher(),
            $this->config,
            $this->inventory()
        );

        $jobId = bin2hex(random_bytes(8));
        $state = $job->start(
            $jobId,
            ['db' => true, 'assets' => false, 'storage' => false, 'extensions' => false],
            ['enabled' => false],
            $targetDialect,
            null
        );
        $state = $this->drive(fn (JobState $s) => $job->runTick($s), $state);

        $this->assertSame('done', $state->get('phase'), 'Export failed: '.$state->get('message'));

        $result = (array) $state->get('result', []);
        return $this->storagePaths->backupsDir().DIRECTORY_SEPARATOR.(string) ($result['filename'] ?? '');
    }

    private function runImport(Connection $target, string $archive): void
    {
        $job = $this->importJob($target);

        $jobId = bin2hex(random_bytes(8));
        $dir = $this->storagePaths->importJobDir($jobId);
        copy($archive, $dir.DIRECTORY_SEPARATOR.'upload.flarum');

        $state = $job->start($jobId, null, true, null, null);
        $state = $this->drive(fn (JobState $s) => $job->runTick($s), $state);

        $this->assertSame('done', $state->get('phase'), 'Import failed: '.$state->get('message'));
    }

    /** Loop a job's runTick until it reaches a terminal phase. */
    private function drive(callable $tick, JobState $state): JobState
    {
        $guard = 0;
        while (! in_array($state->get('phase'), ['done', 'error'], true)) {
            $state = $tick($state);
            if (++$guard > 10000) {
                $this->fail('Job did not terminate within 10000 ticks');
            }
        }
        return $state;
    }

    private function buildBackupsTable(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->dropIfExists('backups');
        $schema->create('backups', function (Blueprint $t) {
            $t->increments('id');
            $t->string('filename', 255);
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->boolean('encrypted')->default(false);
            $t->string('contents', 64)->default('');
            $t->string('flarum_version', 32)->nullable();
            $t->string('php_version', 32)->nullable();
            $t->string('target_dialect', 16)->nullable();
            $t->unsignedInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    }

    /**
     * ImportJob com as dependências de reconciliação. Os dois últimos
     * argumentos entraram junto com o staging de `project/*`; sem eles o
     * job não sabe reconciliar composer.json nem preservar settings.
     */
    private function importJob(\Illuminate\Database\ConnectionInterface $target): ImportJob
    {
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $settings->shouldReceive('set')->andReturnNull();
        $settings->shouldReceive('all')->andReturn([]);

        return new ImportJob(
            $this->storagePaths,
            $this->paths,
            $target,
            $this->cipher(),
            $this->config,
            new \Ramon\Backup\Project\ProjectReconciler($this->paths),
            new \Ramon\Backup\Settings\SettingsPreserver($settings)
        );
    }

    private function cipher(): BackupCipher
    {
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $settings->shouldReceive('set')->andReturnNull();
        return new BackupCipher($settings, $this->config);
    }

    private function inventory(): Inventory
    {
        $manager = Mockery::mock(\Flarum\Extension\ExtensionManager::class);
        return new Inventory($manager, $this->paths);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $this->rrmdir($dir.DIRECTORY_SEPARATOR.$e);
        }
        @rmdir($dir);
    }
}
