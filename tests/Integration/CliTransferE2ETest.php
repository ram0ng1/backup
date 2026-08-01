<?php

namespace Ramon\Backup\Tests\Integration;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule; /* harness de teste standalone, sem boot do Flarum; nosemgrep: flarum-v2-capsule-manager */
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Console\ExportCommand;
use Ramon\Backup\Console\ImportCommand;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Extensions\Inventory;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Fixture;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Full-stack exercise of the two new console commands. It runs the real
 * `backup:export` to produce a `.flarum` archive from one database and
 * the real `backup:import` to restore that archive into a second
 * database, then asserts the data survived the round trip.
 *
 * Unlike {@see CrossEngineTransferTest} (which isolates the SQL
 * dump/restore engine), this drives the whole tick-based ExportJob /
 * ImportJob pipeline — archive packaging, manifest, inspect/extract/
 * restore phases — exactly as the CLI does in production, proving the
 * "loop runTick() to completion with no HTTP budget" approach works.
 *
 * Runs on SQLite so it needs no service container; the cross-engine SQL
 * correctness is covered by the sibling test.
 */
final class CliTransferE2ETest extends TestCase
{
    private string $workdir;
    private Capsule $capsule;
    private Paths $paths;
    private StoragePaths $storagePaths;
    private Config $config;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not loaded');
        }

        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_cli_'.bin2hex(random_bytes(4));
        foreach (['', '/storage', '/public', '/public/assets'] as $sub) {
            @mkdir($this->workdir.$sub, 0777, true);
        }

        $sourceFile = $this->workdir.DIRECTORY_SEPARATOR.'source.sqlite';
        $targetFile = $this->workdir.DIRECTORY_SEPARATOR.'target.sqlite';
        touch($sourceFile);
        touch($targetFile);

        $this->capsule = new Capsule();
        $this->capsule->setEventDispatcher(new Dispatcher(new Container()));
        $this->capsule->addConnection([
            'driver' => 'sqlite', 'database' => $sourceFile, 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'default');
        $this->capsule->addConnection([
            'driver' => 'sqlite', 'database' => $targetFile, 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'target');
        $this->capsule->setAsGlobal();
        // ExportJob's finalize inserts a Backup Eloquent model, so the
        // model layer must resolve to our (source) default connection.
        $this->capsule->bootEloquent();

        $this->paths = new Paths([
            'base'    => $this->workdir,
            'public'  => $this->workdir.DIRECTORY_SEPARATOR.'public',
            'storage' => $this->workdir.DIRECTORY_SEPARATOR.'storage',
        ]);
        $this->storagePaths = new StoragePaths($this->paths);
        $this->config = new Config(['url' => 'http://localhost']);

        $source = $this->capsule->getConnection('default');

        // Fixture::build wipes the whole schema, so seed it BEFORE
        // creating the `backups` registry table the export finalize
        // writes to.
        Fixture::build($source);
        Fixture::seed($source);

        $schema = $source->getSchemaBuilder();
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

    protected function tearDown(): void
    {
        Mockery::close();
        if (isset($this->capsule)) {
            $this->capsule->getConnection('default')->disconnect();
            $this->capsule->getConnection('target')->disconnect();
        }
        $this->rrmdir($this->workdir);
    }

    public function test_export_then_import_round_trips_via_cli(): void
    {
        $source = $this->capsule->getConnection('default');
        $target = $this->capsule->getConnection('target');
        $expected = Fixture::snapshot($source);

        // --- backup:export -------------------------------------------------
        $exportJob = new ExportJob(
            $this->storagePaths,
            $this->paths,
            $source,
            $this->cipher(),
            $this->config,
            $this->inventory()
        );
        $exportTester = new CommandTester(new ExportCommand($exportJob, $this->storagePaths));
        $exportStatus = $exportTester->execute(['--db' => true]);

        $this->assertSame(0, $exportStatus, $exportTester->getDisplay());

        $archives = glob($this->storagePaths->backupsDir().DIRECTORY_SEPARATOR.'*.flarum');
        $this->assertNotEmpty($archives, 'Export produced no .flarum archive');
        $archive = $archives[0];
        $this->assertGreaterThan(0, filesize($archive));

        // --- backup:import -------------------------------------------------
        $importJob = $this->importJob($target);
        $importTester = new CommandTester(new ImportCommand($importJob, $this->storagePaths));
        $importStatus = $importTester->execute([
            'archive' => $archive,
            '--yes'   => true,
        ]);

        $this->assertSame(0, $importStatus, $importTester->getDisplay());

        // --- verify the data transferred ----------------------------------
        $actual = Fixture::snapshot($target);
        $this->assertSame($expected['authors'], $actual['authors']);
        $this->assertSame($expected['posts'], $actual['posts']);
    }

    public function test_import_refuses_without_yes(): void
    {
        $target = $this->capsule->getConnection('target');
        $importJob = $this->importJob($target);
        $tester = new CommandTester(new ImportCommand($importJob, $this->storagePaths));

        $status = $tester->execute(['archive' => __FILE__]); // any existing file
        $this->assertSame(1, $status);
        $this->assertStringContainsString('--yes', $tester->getDisplay());
    }

    /**
     * Security regression: the user-supplied decryption private key must
     * NEVER be written to the on-disk job-state file. We export an
     * encrypted archive, import it with the matching private key, and
     * assert (a) the key never appears in job.json at any point, and
     * (b) decryption still succeeds (data is restored) — proving the
     * in-memory-only key handling didn't break the feature.
     */
    public function test_decryption_key_is_never_persisted_to_job_state(): void
    {
        if (! function_exists('sodium_crypto_box_keypair')) {
            $this->markTestSkipped('libsodium not available');
        }

        $source = $this->capsule->getConnection('default');
        $target = $this->capsule->getConnection('target');
        $expected = Fixture::snapshot($source);

        $keypair = sodium_crypto_box_keypair();
        $publicKey  = base64_encode(sodium_crypto_box_publickey($keypair));
        $privateKey = base64_encode(sodium_crypto_box_secretkey($keypair));

        // Encrypted export to the public key.
        $exportJob = new ExportJob(
            $this->storagePaths,
            $this->paths,
            $source,
            $this->cipher(),
            $this->config,
            $this->inventory()
        );
        $exportId = bin2hex(random_bytes(8));
        $estate = $exportJob->start(
            $exportId,
            ['db' => true, 'assets' => false, 'storage' => false, 'extensions' => false],
            ['enabled' => true, 'public_key' => $publicKey],
            null,
            null
        );
        while (! in_array($estate->get('phase'), ['done', 'error'], true)) {
            $estate = $exportJob->runTick($estate);
        }
        $this->assertSame('done', $estate->get('phase'), 'Encrypted export failed: '.$estate->get('message'));
        $archive = $this->storagePaths->backupsDir().DIRECTORY_SEPARATOR.(string) $estate->get('result')['filename'];

        // Import with the private key, watching the job-state file.
        $importJob = $this->importJob($target);
        $importId = bin2hex(random_bytes(8));
        $importDir = $this->storagePaths->importJobDir($importId);
        copy($archive, $importDir.DIRECTORY_SEPARATOR.'upload.flarum');
        $jobStateFile = $importDir.DIRECTORY_SEPARATOR.'job.json';

        $istate = $importJob->start($importId, $privateKey, true, null, null);

        $this->assertFileExists($jobStateFile);
        $this->assertStringNotContainsString(
            $privateKey,
            (string) file_get_contents($jobStateFile), /* arquivo local do próprio teste; nosemgrep: flarum-v2-server-side-fetch */
            'Private key was persisted into job.json by start()'
        );

        while (! in_array($istate->get('phase'), ['done', 'error'], true)) {
            $istate = $importJob->runTick($istate);
            if (is_file($jobStateFile)) {
                $this->assertStringNotContainsString(
                    $privateKey,
                    (string) file_get_contents($jobStateFile), /* arquivo local do próprio teste; nosemgrep: flarum-v2-server-side-fetch */
                    'Private key leaked into job.json during a tick'
                );
            }
        }

        $this->assertSame('done', $istate->get('phase'), 'Encrypted import failed: '.$istate->get('message'));

        // Decryption worked end to end.
        $actual = Fixture::snapshot($target);
        $this->assertSame($expected['authors'], $actual['authors']);
        $this->assertSame($expected['posts'], $actual['posts']);
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
        // db-only export never calls into the extension manager, so a
        // bare mock is enough to satisfy the constructor.
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
