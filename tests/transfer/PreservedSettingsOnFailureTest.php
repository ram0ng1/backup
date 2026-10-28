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
use Flarum\Settings\DatabaseSettingsRepository;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Archive\ArchiveWriter;
use Ramon\Backup\Archive\Format;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;

/**
 * Um restore que falha no meio já trocou a tabela settings pela da origem.
 * A configuração deste servidor (SMTP, fila) tem que voltar mesmo assim:
 * uma segunda tentativa fotografaria a tabela já poluída.
 */
final class PreservedSettingsOnFailureTest extends TestCase
{
    private string $workdir;

    protected function tearDown(): void
    {
        Mockery::close();
        if (isset($this->workdir)) {
            exec('rm -rf '.escapeshellarg($this->workdir));
        }
    }

    public function test_restore_que_falha_devolve_a_configuracao_deste_servidor(): void
    {
        $db = Engines::connection('sqlite');
        if ($db === null) {
            $this->markTestSkipped('Needs pdo_sqlite');
        }

        $db->getSchemaBuilder()->dropAllTables();
        $db->getSchemaBuilder()->create('settings', function ($t) {
            $t->string('key', 100)->primary();
            $t->text('value')->nullable();
        });
        $db->table('settings')->insert([['key' => 'mail_host', 'value' => 'smtp.destino.example'], ['key' => 'forum_title', 'value' => 'Destino']]);

        $d = DatabaseDumper::STATEMENT_DELIMITER;
        $sql = 'DROP TABLE IF EXISTS "settings"'.$d
            .'CREATE TABLE "settings" ("key" varchar(100) primary key, "value" text)'.$d
            .'INSERT INTO "settings" ("key", "value") VALUES (\'mail_host\', \'smtp.origem.example\'), (\'forum_title\', \'Origem\')'.$d
            .'INSERT INTO "nao_existe" VALUES (1)'.$d;

        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_ps_'.bin2hex(random_bytes(4));
        @mkdir($this->workdir.'/public/assets', 0777, true);
        @mkdir($this->workdir.'/storage', 0777, true);
        $paths = new Paths(['base' => $this->workdir, 'public' => $this->workdir.'/public', 'storage' => $this->workdir.'/storage']);

        $storage = new StoragePaths($paths);
        $jobId = bin2hex(random_bytes(8));
        $writer = ArchiveWriter::createPlain($storage->importJobDir($jobId).'/upload.flarum', ['format_version' => 3, 'contents' => ['db'], 'manifest' => []]);
        $writer->beginEntry(Format::DB_ENTRY_NAME, Format::TYPE_DB_DUMP, strlen($sql));
        $writer->appendEntryBytes($sql);
        $writer->finalize();
        $writer->close();

        $settings = new DatabaseSettingsRepository($db);
        $cipherSettings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $cipherSettings->shouldReceive('get')->andReturn('');
        $config = new Config(['url' => 'http://localhost']);
        $job = new ImportJob($storage, $paths, $db, new BackupCipher($cipherSettings, $config), $config, new ProjectReconciler($paths, new Messages()), new SettingsPreserver($settings), new Messages());

        $state = $job->start($jobId, null, true, null, null);
        for ($i = 0; $i < 50 && ! in_array($state->get('phase'), ['done', 'error'], true); $i++) {
            $job->runTick($state);
        }

        $this->assertSame('error', $state->get('phase'));
        $this->assertSame('smtp.destino.example', $db->table('settings')->where('key', 'mail_host')->value('value'));
        // A tabela settings só é trocada no fim do restore; uma falha antes
        // disso deixa a deste servidor como estava.
        $this->assertSame('Destino', $db->table('settings')->where('key', 'forum_title')->value('value'));
    }
}
