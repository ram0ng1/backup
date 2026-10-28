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
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Fixture;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Roda os comandos de console de verdade: `backup:export` gera um
 * `.flarum` de um banco e `backup:import` o restaura em outro, e os dados
 * têm que chegar intactos.
 *
 * Diferente do {@see CrossEngineTransferTest}, que isola o dump SQL, aqui
 * passa o pipeline inteiro de ticks do ExportJob/ImportJob (pacote,
 * manifesto, inspect/extract/restore), como a CLI faz em produção, com
 * runTick() em laço e sem orçamento HTTP.
 *
 * Roda em SQLite, sem serviço externo; a correção do SQL entre motores
 * fica com o teste irmão.
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
        // O finalize do ExportJob grava um model Backup, que precisa resolver na
        // conexão padrão (a de origem).
        $this->capsule->bootEloquent();

        $this->paths = new Paths([
            'base'    => $this->workdir,
            'public'  => $this->workdir.DIRECTORY_SEPARATOR.'public',
            'storage' => $this->workdir.DIRECTORY_SEPARATOR.'storage',
        ]);
        $this->storagePaths = new StoragePaths($this->paths);
        $this->config = new Config(['url' => 'http://localhost']);

        $source = $this->capsule->getConnection('default');

        // Fixture::build apaga o schema inteiro, então ela vem ANTES da tabela
        // `backups` que o finalize do export usa.
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
            $this->inventory(),
            new Messages()
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

        // --- confere os dados transferidos ---------------------------------
        $actual = Fixture::snapshot($target);
        $this->assertSame($expected['authors'], $actual['authors']);
        $this->assertSame($expected['posts'], $actual['posts']);
    }

    public function test_import_refuses_without_yes(): void
    {
        $target = $this->capsule->getConnection('target');
        $importJob = $this->importJob($target);
        $tester = new CommandTester(new ImportCommand($importJob, $this->storagePaths));

        $status = $tester->execute(['archive' => __FILE__]); // qualquer arquivo existente
        $this->assertSame(1, $status);
        $this->assertStringContainsString('--yes', $tester->getDisplay());
    }

    /**
     * Segurança: a chave privada informada para descriptografar NUNCA pode
     * ir para o job.json em disco. Exporta criptografado, importa com a
     * chave e confere que (a) ela nunca aparece no job.json e (b) a
     * restauração funciona, ou seja, a chave só em memória não quebrou nada.
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

        // Export criptografado com a chave pública.
        $exportJob = new ExportJob(
            $this->storagePaths,
            $this->paths,
            $source,
            $this->cipher(),
            $this->config,
            $this->inventory(),
            new Messages()
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

        // Import com a chave privada, vigiando o arquivo de estado do job.
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

        // A descriptografia funcionou de ponta a ponta.
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
            new \Ramon\Backup\Project\ProjectReconciler($this->paths, new Messages()),
            new \Ramon\Backup\Settings\SettingsPreserver($settings),
            new Messages()
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
        // Export só de banco nunca chama o gerenciador de extensões; um mock vazio
        // satisfaz o construtor.
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
