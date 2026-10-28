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
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;

/**
 * Restore pela web: cada tick é uma requisição nova, que precisa iniciar o
 * Flarum (tabela `settings`) e autenticar o admin (`users`, `access_tokens`,
 * grupos). Essas tabelas têm que continuar as do destino entre um tick e
 * outro, e o job termina no mesmo tick em que elas viram as da origem.
 */
final class WebRestoreAcrossTicksTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_web_'.bin2hex(random_bytes(4));
        @mkdir($this->workdir.'/job', 0777, true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        exec('rm -rf '.escapeshellarg($this->workdir));
    }

    #[DataProvider('targets')]
    public function test_tabelas_de_boot_sobrevivem_entre_ticks(string $target): void
    {
        if (Engines::connection($target) === null) {
            $this->markTestSkipped("$target not available");
        }

        // Origem: SQLite em memória, com ids sem sinal (o destino MySQL usa
        // UNSIGNED e recusaria a FK contra a tabela antiga).
        $capsule = new Capsule();
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'web_src');
        $source = $capsule->getConnection('web_src');
        $this->schema($source);
        $source->table('settings')->insert(['key' => 'forum_title', 'value' => 'Origem']);
        $source->table('users')->insert(['id' => 1, 'username' => 'admin-origem']);
        $source->table('access_tokens')->insert(['token' => 'token-origem', 'user_id' => 1]);
        $rows = array_map(fn ($i) => ['id' => $i, 'user_id' => 1, 'content' => str_repeat('p', 5000)], range(1, 1300));
        foreach (array_chunk($rows, 200) as $chunk) {
            $source->table('posts')->insert($chunk);
        }

        // Destino com os próprios dados (o admin logado está aqui).
        $dest = Engines::connection($target);
        $dest->getSchemaBuilder()->dropAllTables();
        $this->schema($dest, unsigned: true);
        $dest->table('settings')->insert(['key' => 'forum_title', 'value' => 'Destino']);
        $dest->table('users')->insert(['id' => 1, 'username' => 'admin-destino']);
        $dest->table('access_tokens')->insert(['token' => 'token-destino', 'user_id' => 1]);

        $dumper = new DatabaseDumper($source, Dialect::parse($target));
        $sql = $dumper->preamble().$dumper->dropAllTables();
        foreach ($dumper->listTables() as $table) {
            $sql .= $dumper->dumpSchema($table);
            $offset = 0;
            $after = null;
            while (($batch = $dumper->dumpDataBatch($table, $offset, $after))['consumed'] > 0) {
                $sql .= $batch['sql'];
                $offset += $batch['consumed'];
                $after = $batch['after_key'];
            }
        }
        $sql .= $dumper->epilogue();

        $dir = $this->workdir.'/job';
        file_put_contents("$dir/dump.sql", $sql);
        $state = JobState::create("$dir/job.json", [
            'phase'    => 'restore',
            'paths'    => ['dir' => $dir, 'dump' => "$dir/dump.sql", 'archive' => "$dir/upload.flarum"],
            'progress' => ['restored_statements' => 0],
            'cursor'   => ['restore_offset' => 0],
        ]);

        $ticks = 0;
        while ($state->get('phase') === 'restore' && $ticks < 20) {
            $this->job(Engines::freshConnection($target))->runTick($state);
            $ticks++;

            if ($state->get('phase') === 'restore') {
                // Entre ticks: a próxima requisição consegue iniciar e autenticar.
                $check = Engines::freshConnection($target);
                $this->assertSame('Destino', $check->table('settings')->where('key', 'forum_title')->value('value'));
                $this->assertSame('token-destino', $check->table('access_tokens')->value('token'));
                $this->assertSame('admin-destino', $check->table('users')->value('username'));
            }
        }

        $this->assertGreaterThan(1, $ticks, 'o dump deveria ocupar mais de um tick');
        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));

        $after = Engines::freshConnection($target);
        $this->assertSame('Origem', $after->table('settings')->where('key', 'forum_title')->value('value'));
        $this->assertSame('admin-origem', $after->table('users')->value('username'));
        $this->assertSame('token-origem', $after->table('access_tokens')->value('token'));
        $this->assertSame(1300, (int) $after->table('posts')->count());
        $this->assertFileDoesNotExist("$dir/dump.deferred.sql");

        // A FK dos posts para users voltou depois da troca.
        if ($target !== 'sqlite') {
            DatabaseRestoreHelper::assertForeignKey($this, $after, 'posts', 'users');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        foreach (Engines::ALL as $engine) {
            yield $engine => [$engine];
        }
    }

    private function schema(Connection $db, bool $unsigned = false): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->create('settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->text('value')->nullable();
        });
        $schema->create('users', function (Blueprint $t) use ($unsigned) {
            $unsigned ? $t->increments('id') : $t->integer('id')->primary();
            $t->string('username');
        });
        $schema->create('access_tokens', function (Blueprint $t) use ($unsigned) {
            $t->string('token', 40)->primary();
            $unsigned ? $t->unsignedInteger('user_id') : $t->integer('user_id');
            $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        $schema->create('posts', function (Blueprint $t) use ($unsigned) {
            $t->increments('id');
            $unsigned ? $t->unsignedInteger('user_id')->nullable() : $t->integer('user_id')->nullable();
            $t->text('content');
            $t->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    private function job(Connection $db): ImportJob
    {
        $paths = new Paths(['base' => $this->workdir, 'public' => $this->workdir, 'storage' => $this->workdir]);
        $config = new Config(['url' => 'http://localhost']);
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $settings->shouldReceive('all')->andReturn([]);

        return new ImportJob(new StoragePaths($paths), $paths, $db, new BackupCipher($settings, $config), $config, new ProjectReconciler($paths), new SettingsPreserver($settings));
    }
}

/** Confere FKs pelo catálogo de cada motor. */
final class DatabaseRestoreHelper
{
    public static function assertForeignKey(TestCase $test, Connection $db, string $table, string $references): void
    {
        $count = match ($db->getDriverName()) {
            'pgsql' => $db->selectOne(
                "SELECT COUNT(*) AS c FROM pg_constraint WHERE contype = 'f' AND conrelid = ?::regclass AND confrelid = ?::regclass",
                [$table, $references]
            )->c,
            default => $db->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = ?',
                [$table, $references]
            )->c,
        };

        $test->assertGreaterThan(0, (int) $count, "FK de $table para $references não existe");
    }
}
