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
use Illuminate\Database\Connection;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Database\Dialect;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Project\ProjectReconciler;
use Ramon\Backup\Settings\SettingsPreserver;
use Ramon\Backup\StoragePaths;
use Ramon\Backup\Tests\Support\Engines;

/**
 * O preâmbulo do dump (`SET ...`) roda no primeiro tick do restore. Pela
 * web cada tick abre uma conexão nova, então o que ele ajusta precisa
 * valer nos ticks seguintes também.
 */
final class RestoreSessionStateTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_ss_'.bin2hex(random_bytes(4));
        @mkdir($this->workdir.DIRECTORY_SEPARATOR.'job', 0777, true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        foreach (glob($this->workdir.'/job/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workdir.'/job');
        @rmdir($this->workdir);
    }

    /**
     * Com `standard_conforming_strings = off` (configuração do banco ou do
     * papel), uma barra invertida dentro de um literal escapa a aspa. Um
     * post com `\'); DROP TABLE ...` depois do primeiro tick saía do literal.
     */
    public function test_postgres_mantem_strings_padrao_em_todos_os_ticks(): void
    {
        $sqlite = Engines::connection('sqlite');
        $pg = Engines::connection('postgres');
        if ($sqlite === null || $pg === null) {
            $this->markTestSkipped('Needs postgres + sqlite');
        }

        $database = $pg->getDatabaseName();
        $pg->unprepared('DROP TABLE IF EXISTS bkp_victim');
        $pg->unprepared('CREATE TABLE bkp_victim (id int)');
        $pg->unprepared('INSERT INTO bkp_victim VALUES (42)');
        $pg->unprepared("ALTER DATABASE \"$database\" SET standard_conforming_strings = off");

        try {
            $payload = "\\'); DROP TABLE bkp_victim; --";
            $this->seedSource($sqlite, $payload);

            $this->restoreInTicks($sqlite, 'postgres');

            $check = Engines::freshConnection('postgres');
            $this->assertNotNull($check->selectOne("SELECT to_regclass('public.bkp_victim') AS t")->t, 'bkp_victim foi apagada pelo conteúdo do post');
            $this->assertSame($payload, $check->table('bkp_tail')->value('body'));
        } finally {
            $pg->unprepared("ALTER DATABASE \"$database\" RESET standard_conforming_strings");
            $pg->unprepared('DROP TABLE IF EXISTS bkp_victim');
        }
    }

    /**
     * `NO_AUTO_VALUE_ON_ZERO` do preâmbulo: sem ele, um id 0 restaurado
     * depois do primeiro tick vira o próximo auto-increment.
     */
    public function test_mysql_mantem_o_id_zero_em_todos_os_ticks(): void
    {
        $sqlite = Engines::connection('sqlite');
        $engine = Engines::connection('mysql') !== null ? 'mysql' : (Engines::connection('mariadb') !== null ? 'mariadb' : null);
        if ($sqlite === null || $engine === null) {
            $this->markTestSkipped('Needs mysql or mariadb + sqlite');
        }

        $this->seedSource($sqlite, 'zero', 0);

        $this->restoreInTicks($sqlite, $engine);

        $check = Engines::freshConnection($engine);
        $this->assertSame('zero', $check->table('bkp_tail')->where('id', 0)->value('body'));
    }

    private function seedSource(Connection $sqlite, string $last, ?int $lastId = null): void
    {
        $schema = $sqlite->getSchemaBuilder();
        $schema->dropAllTables();
        $schema->create('bkp_padded', function ($t) {
            $t->increments('id');
            $t->text('body');
        });

        // ~6 MB antes da linha que importa, para ela cair no segundo tick.
        $rows = array_fill(0, 1200, ['body' => str_repeat('p', 5000)]);
        foreach (array_chunk($rows, 200) as $chunk) {
            $sqlite->table('bkp_padded')->insert($chunk);
        }

        // As tabelas saem em ordem alfabética: bkp_tail vem depois do enchimento.
        $schema->create('bkp_tail', function ($t) {
            $t->increments('id');
            $t->text('body');
        });
        $sqlite->table('bkp_tail')->insert(array_filter(['id' => $lastId, 'body' => $last], fn ($v) => $v !== null));
    }

    private function restoreInTicks(Connection $source, string $target): void
    {
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

        $dir = $this->workdir.DIRECTORY_SEPARATOR.'job';
        file_put_contents($dir.'/dump.sql', $sql);
        $state = JobState::create($dir.'/job.json', [
            'phase'    => 'restore',
            'paths'    => ['dir' => $dir, 'dump' => $dir.'/dump.sql', 'archive' => $dir.'/upload.flarum'],
            'progress' => ['restored_statements' => 0],
            'cursor'   => ['restore_offset' => 0],
        ]);

        $paths = new Paths(['base' => $this->workdir, 'public' => $this->workdir, 'storage' => $this->workdir]);
        $config = new Config(['url' => 'http://localhost']);
        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');

        $ticks = 0;
        do {
            $job = new ImportJob(
                new StoragePaths($paths),
                $paths,
                Engines::freshConnection($target),
                new BackupCipher($settings, $config),
                $config,
                new ProjectReconciler($paths, new Messages()),
                new SettingsPreserver($settings),
                new Messages()
            );
            $job->runTick($state);
        } while ($state->get('phase') === 'restore' && ++$ticks < 20);

        $this->assertGreaterThan(0, $ticks, 'o dump precisava de mais de um tick');
        // O último tick do restore já conclui o job (ver ImportJob::runRestore).
        $this->assertSame('done', $state->get('phase'), (string) $state->get('message'));
    }
}
