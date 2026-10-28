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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Extensions\Inventory;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;

/**
 * Arquivos mudam enquanto o export anda: log que gira, sessão regravada,
 * upload apagado. O cabeçalho da entrada já declarou o tamanho do scan, e
 * o leitor confia nele; o arquivo tem que continuar legível do começo ao
 * fim, e os arquivos seguintes intactos.
 */
final class ExportFileChangesTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        if (Engines::connection('sqlite') === null) {
            $this->markTestSkipped('Needs pdo_sqlite');
        }
        Engines::bootEloquent();
        Engines::setDefaultConnection('sqlite');

        $schema = Engines::connection('sqlite')->getSchemaBuilder();
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

        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_fc_'.bin2hex(random_bytes(4));
        foreach (['src/public/assets', 'src/storage', 'dst/public/assets', 'dst/storage'] as $sub) {
            @mkdir($this->workdir.'/'.$sub, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        exec('rm -rf '.escapeshellarg($this->workdir));
    }

    /** @return array<string, array{string}> */
    public static function changes(): array
    {
        return [
            'encolheu' => ['shrink'],
            'sumiu'    => ['vanish'],
        ];
    }

    #[DataProvider('changes')]
    public function test_arquivo_que_muda_no_meio_nao_corrompe_o_backup(string $change): void
    {
        $assets = $this->workdir.'/src/public/assets';
        file_put_contents("$assets/a.bin", str_repeat('A', 6 * 1024 * 1024));
        file_put_contents("$assets/b.bin", str_repeat('B', 6 * 1024 * 1024));
        file_put_contents("$assets/c.txt", 'fim');

        $paths = $this->paths('src');
        $job = new ExportJob(new StoragePaths($paths), $paths, Engines::connection('sqlite'), $this->cipher(), new Config(['url' => 'http://localhost']), new Inventory(Mockery::mock(\Flarum\Extension\ExtensionManager::class), $paths));
        $state = $job->start(bin2hex(random_bytes(8)), ['db' => false, 'assets' => true, 'storage' => false, 'extensions' => false], ['enabled' => false], null, null);

        $changed = false;
        while (! in_array($state->get('phase'), ['done', 'error'], true)) {
            $job->runTick($state);
            // O primeiro tick do bundle grava o cabeçalho de a.bin e 4 MB dele.
            if (! $changed && $state->get('phase') === 'bundle' && $state->get('cursor')['file_offset'] > 0) {
                $change === 'shrink' ? file_put_contents("$assets/a.bin", 'curto') : unlink("$assets/a.bin");
                $changed = true;
            }
        }
        $this->assertTrue($changed);
        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));

        $archive = (new StoragePaths($paths))->backupsDir().'/'.$state->get('result')['filename'];

        $dst = $this->paths('dst');
        $dstStorage = new StoragePaths($dst);
        $jobId = bin2hex(random_bytes(8));
        copy($archive, $dstStorage->importJobDir($jobId).'/upload.flarum');

        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $settings->shouldReceive('all')->andReturn([]);
        $import = new ImportJob($dstStorage, $dst, Engines::connection('sqlite'), $this->cipher(), new Config(['url' => 'http://localhost']), new ProjectReconciler($dst), new SettingsPreserver($settings));
        $state = $import->start($jobId, null, true, ['db' => false, 'assets' => true, 'storage' => false, 'extensions' => false], null);
        while (! in_array($state->get('phase'), ['done', 'error'], true)) {
            $import->runTick($state);
        }

        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));
        $restored = $this->workdir.'/dst/public/assets';
        $this->assertSame(str_repeat('B', 6 * 1024 * 1024), file_get_contents("$restored/b.bin"));
        $this->assertSame('fim', file_get_contents("$restored/c.txt"));
    }

    private function paths(string $side): Paths
    {
        return new Paths([
            'base'    => $this->workdir.'/'.$side,
            'public'  => $this->workdir.'/'.$side.'/public',
            'storage' => $this->workdir.'/'.$side.'/storage',
        ]);
    }

    private function cipher(): BackupCipher
    {
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');

        return new BackupCipher($settings, new Config(['url' => 'http://localhost']));
    }
}
