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
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Archive\ArchiveWriter;
use Ramon\Backup\Archive\Format;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;

/**
 * Pela web cada tick tem o tempo de uma requisição. Uma entrada grande (o
 * database.sql de um fórum real tem centenas de MB) precisa se espalhar
 * por vários ticks, e um tick repetido depois de um timeout não pode
 * duplicar o que já foi gravado.
 */
final class ExtractTickBudgetTest extends TestCase
{
    private string $workdir;
    private Paths $paths;

    protected function setUp(): void
    {
        if (Engines::connection('sqlite') === null) {
            $this->markTestSkipped('Needs pdo_sqlite');
        }

        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_xt_'.bin2hex(random_bytes(4));
        @mkdir($this->workdir.'/public/assets', 0777, true);
        @mkdir($this->workdir.'/storage', 0777, true);
        $this->paths = new Paths(['base' => $this->workdir, 'public' => $this->workdir.'/public', 'storage' => $this->workdir.'/storage']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        exec('rm -rf '.escapeshellarg($this->workdir));
    }

    public function test_entradas_grandes_se_espalham_pelos_ticks_sem_duplicar(): void
    {
        $sql = '';
        for ($i = 0; $i < 2000; $i++) {
            $sql .= sprintf("INSERT INTO t VALUES (%d, '%s');\n", $i, str_repeat(chr(65 + $i % 26), 5000));
        }
        $file = random_bytes(6 * 1024 * 1024);

        $archive = $this->workdir.'/a.flarum';
        $writer = ArchiveWriter::createPlain($archive, ['format_version' => 3, 'contents' => ['db', 'assets'], 'manifest' => []]);
        $writer->beginEntry(Format::DB_ENTRY_NAME, Format::TYPE_DB_DUMP, strlen($sql));
        $writer->appendEntryBytes($sql);
        $writer->beginEntry('assets/big.bin', Format::TYPE_FILE, strlen($file));
        $writer->appendEntryBytes($file);
        $writer->finalize();
        $writer->close();

        $storage = new StoragePaths($this->paths);
        $jobId = bin2hex(random_bytes(8));
        copy($archive, $storage->importJobDir($jobId).'/upload.flarum');

        $job = $this->job();
        $state = $job->start($jobId, null, true, ['db' => true, 'assets' => true, 'storage' => false, 'extensions' => false], null);
        $job->runTick($state); // inspect

        $ticks = 0;
        $replayed = false;
        while ($state->get('phase') === 'extract' && $ticks < 50) {
            $before = (string) file_get_contents($state->file());
            $job->runTick($state);
            $ticks++;

            // Simula um tick que gravou e morreu antes de salvar o estado:
            // o próximo recomeça do job.json anterior.
            if ($ticks === 2 && ! $replayed && $state->get('phase') === 'extract') {
                file_put_contents($state->file(), $before);
                $state = JobState::load($state->file());
                $replayed = true;
            }
        }

        $this->assertTrue($replayed);
        $this->assertSame('reconcile', $state->get('phase'), (string) $state->get('message'));
        $this->assertSame(md5($sql), md5_file($state->get('paths')['dump']), 'dump.sql diferente do que o arquivo trazia');
        $this->assertSame(md5($file), md5_file($this->workdir.'/public/assets/big.bin'));
        $this->assertGreaterThan(4, $ticks, 'cada tick deveria parar perto do orçamento');
    }

    private function job(): ImportJob
    {
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $settings->shouldReceive('all')->andReturn([]);
        $config = new Config(['url' => 'http://localhost']);

        return new ImportJob(new StoragePaths($this->paths), $this->paths, Engines::connection('sqlite'), new BackupCipher($settings, $config), $config, new ProjectReconciler($this->paths), new SettingsPreserver($settings));
    }
}
