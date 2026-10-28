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
use Illuminate\Database\Schema\Blueprint;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Extensions\Inventory;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;
use Ramon\Backup\Tests\Support\Fixture;

/**
 * O diretório do job sobrevive ao fim do job (até o painel ou a limpeza
 * diária apagarem). O que ficar nele não pode decifrar o backup nem
 * guardar o banco em claro.
 */
final class JobSecretsTest extends TestCase
{
    private string $workdir;
    private Paths $paths;
    private Config $config;
    private string $publicKey;

    protected function setUp(): void
    {
        if (Engines::connection('sqlite') === null || ! function_exists('sodium_crypto_box_keypair')) {
            $this->markTestSkipped('Needs pdo_sqlite + sodium');
        }
        Engines::bootEloquent();
        Engines::setDefaultConnection('sqlite');

        $db = Engines::connection('sqlite');
        Fixture::build($db);
        Fixture::seed($db);
        $db->getSchemaBuilder()->create('backups', function (Blueprint $t) {
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

        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_sec_'.bin2hex(random_bytes(4));
        @mkdir($this->workdir.'/public/assets', 0777, true);
        @mkdir($this->workdir.'/storage', 0777, true);
        $this->paths = new Paths(['base' => $this->workdir, 'public' => $this->workdir.'/public', 'storage' => $this->workdir.'/storage']);

        $keypair = sodium_crypto_box_keypair();
        $this->publicKey = base64_encode(sodium_crypto_box_publickey($keypair));
        $this->config = new Config([
            'url' => 'http://localhost',
            BackupCipher::CONFIG_PRIVATE_KEY => base64_encode(sodium_crypto_box_secretkey($keypair)),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        if (isset($this->workdir)) {
            exec('rm -rf '.escapeshellarg($this->workdir));
        }
    }

    public function test_export_e_import_cifrados_terminam_sem_estado_de_criptografia(): void
    {
        $export = $this->exportJob();
        $state = $export->start(bin2hex(random_bytes(8)), ['db' => true, 'assets' => false, 'storage' => false, 'extensions' => false], ['enabled' => true], null, null);
        $this->drive($export, $state);

        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));
        $this->assertArrayNotHasKey('binary', $this->onDisk($state));

        $archive = (new StoragePaths($this->paths))->backupsDir().'/'.$state->get('result')['filename'];
        $jobId = bin2hex(random_bytes(8));
        copy($archive, (new StoragePaths($this->paths))->importJobDir($jobId).'/upload.flarum');

        $import = $this->importJob();
        $state = $import->start($jobId, null, true, ['db' => true, 'assets' => false, 'storage' => false, 'extensions' => false, 'preserve_settings' => false], null);
        $this->drive($import, $state);

        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));
        $this->assertArrayNotHasKey('binary', $this->onDisk($state));
    }

    public function test_export_com_erro_apaga_o_dump_em_claro(): void
    {
        $export = $this->exportJob();
        // Chave externa inválida: o erro só aparece no bundle, com o dump já pronto.
        $state = $export->start(bin2hex(random_bytes(8)), ['db' => true, 'assets' => false, 'storage' => false, 'extensions' => false], ['enabled' => true, 'public_key' => 'bm90LWEta2V5'], null, null);
        $this->drive($export, $state);

        $this->assertSame('error', $state->get('phase'));
        $this->assertFileDoesNotExist($state->get('paths')['dump']);
        $this->assertFileDoesNotExist($state->get('paths')['archive']);
    }

    /** @return array<string, mixed> */
    private function onDisk(JobState $state): array
    {
        return json_decode((string) file_get_contents($state->file()), true);
    }

    private function drive(ExportJob|ImportJob $job, JobState $state): void
    {
        for ($i = 0; $i < 1000 && ! in_array($state->get('phase'), ['done', 'error'], true); $i++) {
            $job->runTick($state);
        }
    }

    private function cipher(): BackupCipher
    {
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with(BackupCipher::SETTING_PUBLIC_KEY, '')->andReturn($this->publicKey);
        $settings->shouldReceive('get')->andReturn('');

        return new BackupCipher($settings, $this->config);
    }

    private function exportJob(): ExportJob
    {
        return new ExportJob(new StoragePaths($this->paths), $this->paths, Engines::connection('sqlite'), $this->cipher(), $this->config, new Inventory(Mockery::mock(\Flarum\Extension\ExtensionManager::class), $this->paths), new Messages());
    }

    private function importJob(): ImportJob
    {
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $settings->shouldReceive('all')->andReturn([]);

        return new ImportJob(new StoragePaths($this->paths), $this->paths, Engines::connection('sqlite'), $this->cipher(), $this->config, new ProjectReconciler($this->paths, new Messages()), new SettingsPreserver($settings), new Messages());
    }
}
