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
use Illuminate\Database\SQLiteConnection;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Archive\ArchiveReader;
use Ramon\Backup\Archive\ArchiveWriter;
use Ramon\Backup\Archive\Format;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Extensions\Inventory;
use Ramon\Backup\Job\ExportJob;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;
use Ramon\Backup\Tests\Support\TempDir;

/**
 * Fórum em SQLite com o banco dentro de storage/ (o caminho comum).
 *
 * O backup de "Storage" levava o .sqlite vivo, e o restore o gravava por
 * cima do banco em uso: no tick seguinte, "database disk image is
 * malformed" e o fórum fora do ar. O banco já viaja no dump SQL.
 */
final class LiveSqliteFileTest extends TestCase
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

        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_live_'.bin2hex(random_bytes(4));
        foreach (['src/public/assets', 'src/storage', 'dst/public/assets', 'dst/storage'] as $sub) {
            @mkdir($this->workdir.'/'.$sub, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        TempDir::remove($this->workdir);
    }

    public function test_export_de_storage_nao_leva_o_banco_sqlite_vivo(): void
    {
        $storage = $this->workdir.'/src/storage';
        $db = $this->forumDatabase("$storage/flarum.sqlite");
        file_put_contents("$storage/flarum.sqlite-wal", 'wal');
        file_put_contents("$storage/nota.txt", 'fica');

        $paths = $this->paths('src');
        $job = new ExportJob(new StoragePaths($paths), $paths, $db, $this->cipher(), new Config(['url' => 'http://localhost']), new Inventory(Mockery::mock(\Flarum\Extension\ExtensionManager::class), $paths), new Messages());
        $state = $job->start(bin2hex(random_bytes(8)), ['db' => false, 'assets' => false, 'storage' => true, 'extensions' => false], ['enabled' => false], null, null);
        while (! in_array($state->get('phase'), ['done', 'error'], true)) {
            $job->runTick($state);
        }
        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));

        $names = $this->entryNames((new StoragePaths($paths))->backupsDir().'/'.$state->get('result')['filename']);

        $this->assertContains('storage/nota.txt', $names);
        $this->assertNotContains('storage/flarum.sqlite', $names);
        $this->assertNotContains('storage/flarum.sqlite-wal', $names);
    }

    public function test_restore_de_arquivo_antigo_nao_sobrescreve_o_banco_vivo(): void
    {
        $storage = $this->workdir.'/dst/storage';
        $db = $this->forumDatabase("$storage/flarum.sqlite");
        $before = (string) file_get_contents("$storage/flarum.sqlite");

        $dst = $this->paths('dst');
        $dstStorage = new StoragePaths($dst);
        $jobId = bin2hex(random_bytes(8));
        $archive = $dstStorage->importJobDir($jobId).'/upload.flarum';
        $writer = ArchiveWriter::createPlain($archive, ['format_version' => 3, 'contents' => ['storage'], 'source_url' => '']);
        foreach (['storage/flarum.sqlite' => str_repeat("\xFF", 8192), 'storage/nota.txt' => 'fica'] as $name => $bytes) {
            $writer->beginEntry($name, Format::TYPE_FILE, strlen($bytes));
            $writer->appendEntryBytes($bytes);
        }
        $writer->finalize();
        $writer->close();

        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $settings->shouldReceive('all')->andReturn([]);
        $import = new ImportJob($dstStorage, $dst, $db, $this->cipher(), new Config(['url' => 'http://localhost']), new ProjectReconciler($dst, new Messages()), new SettingsPreserver($settings), new Messages());
        $state = $import->start($jobId, null, true, ['db' => false, 'assets' => false, 'storage' => true, 'extensions' => false], null);
        while (! in_array($state->get('phase'), ['done', 'error'], true)) {
            $import->runTick($state);
        }

        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));
        $this->assertSame('fica', file_get_contents("$storage/nota.txt"));
        $this->assertSame($before, file_get_contents("$storage/flarum.sqlite"));
        $this->assertSame(1, (int) $db->table('discussions')->count());
    }

    private function forumDatabase(string $file): SQLiteConnection
    {
        $db = new SQLiteConnection(new PDO('sqlite:'.$file), $file, '', ['driver' => 'sqlite', 'database' => $file]);
        $db->getSchemaBuilder()->create('discussions', function (Blueprint $t) {
            $t->increments('id');
            $t->string('title');
        });
        $db->table('discussions')->insert(['title' => 'antes do restore']);

        return $db;
    }

    /** @return list<string> */
    private function entryNames(string $archive): array
    {
        $reader = ArchiveReader::openHeader($archive);
        $names = [];
        while ($entry = $reader->nextEntry()) {
            $names[] = $entry['name'];
            for ($left = $entry['length']; $left > 0;) {
                $left -= strlen($reader->readEntryBytes((int) min(1 << 20, $left)));
            }
        }
        $reader->close();

        return $names;
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
