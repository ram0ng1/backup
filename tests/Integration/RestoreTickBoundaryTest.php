<?php

namespace Ramon\Backup\Tests\Integration;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Database\DatabaseDumper;
use Ramon\Backup\Database\DatabaseRestorer;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;
use ReflectionMethod;

/**
 * Guarda de regressão da fronteira de tick no restore.
 *
 * O `DatabaseRestorer` não sobrevive ao tick, mas o `restore_offset` era
 * salvo com os bytes que ainda estavam no buffer dele — tudo depois do
 * último delimitador. Resultado: a cabeça do statement se perdia e o tick
 * seguinte reabria o `dump.sql` no meio de um literal, mandando a cauda do
 * INSERT sozinha para o banco. O sintoma era um `SQLSTATE[42000] ... near
 * 'rs" says it all.<br/>...'` — um pedaço de post virando SQL.
 *
 * Só aparecia quando um statement cruzava o orçamento de 4 MB do tick, ou
 * seja, em dump de fórum real. O harness de teste existente (Support\Transfer)
 * alimenta o dump inteiro de uma vez e por isso nunca cruzava fronteira
 * nenhuma.
 */
final class RestoreTickBoundaryTest extends TestCase
{
    private string $workdir;
    private ImportJob $job;
    private ReflectionMethod $runRestore;

    /** @var list<string> */
    private array $executed = [];

    protected function setUp(): void
    {
        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_tb_'.bin2hex(random_bytes(4));
        foreach (['', '/public', '/storage', '/jobdir'] as $sub) {
            @mkdir($this->workdir.$sub, 0777, true);
        }

        $paths = new Paths([
            'base'    => $this->workdir,
            'public'  => $this->workdir.DIRECTORY_SEPARATOR.'public',
            'storage' => $this->workdir.DIRECTORY_SEPARATOR.'storage',
        ]);
        $config = new Config(['url' => 'http://localhost']);

        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');

        /*
         * Driver sqlite é o mock mais barato: `Dialect::detect` resolve sem
         * consultar o servidor e o resync de sequences do Postgres sai cedo.
         */
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('sqlite');
        $db->shouldReceive('unprepared')->andReturnUsing(function (string $sql): bool {
            $this->executed[] = $sql;
            return true;
        });

        $this->job = new ImportJob(
            new StoragePaths($paths),
            $paths,
            $db,
            new BackupCipher($settings, $config),
            $config,
            new \Ramon\Backup\Project\ProjectReconciler($paths),
            new \Ramon\Backup\Settings\SettingsPreserver($settings)
        );

        $this->runRestore = new ReflectionMethod($this->job, 'runRestore');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->rrmdir($this->workdir);
    }

    /**
     * Statements dimensionados para que o orçamento de um tick caia DENTRO
     * do INSERT grande: enchemos quase 4 MB com statements pequenos e só
     * então emitimos um de ~1 MB.
     *
     * @return list<string>
     */
    private function buildStatements(): array
    {
        $statements = ['SET FOREIGN_KEY_CHECKS = 0'];

        $filler = 0;
        while ($filler < ImportJob::BUDGET_BYTES - 262144) {
            $n = count($statements);
            $stmt = "INSERT INTO posts VALUES ($n,'".str_repeat('x', 4000)."')";
            $statements[] = $stmt;
            $filler += strlen($stmt) + strlen(DatabaseDumper::STATEMENT_DELIMITER);
        }

        /*
         * O statement que cruza a fronteira. O conteúdo carrega aspas e
         * quebras de linha de propósito: é assim que um post real parece, e
         * é o que aparecia truncado no erro de sintaxe.
         */
        $big = "INSERT INTO posts VALUES ";
        for ($i = 0; $i < 260; $i++) {
            $big .= ($i > 0 ? ',' : '')
                ."($i,'<r><p>In #3, the colon should not be used there.<br/>"
                .str_repeat('padding ', 480)."</p></r>')";
        }
        $statements[] = $big;

        $statements[] = "INSERT INTO users VALUES (1,'depois da fronteira')";
        $statements[] = 'SET FOREIGN_KEY_CHECKS = 1';

        return $statements;
    }

    private function state(string $dumpFile): JobState
    {
        return JobState::create(
            $this->workdir.DIRECTORY_SEPARATOR.'job_'.bin2hex(random_bytes(3)).'.json',
            [
                'paths'    => ['dir' => $this->workdir.DIRECTORY_SEPARATOR.'jobdir', 'dump' => $dumpFile],
                'progress' => ['restored_statements' => 0],
                'cursor'   => ['restore_offset' => 0],
                'phase'    => 'restore',
            ]
        );
    }

    public function test_statement_straddling_the_tick_budget_is_executed_whole_and_once(): void
    {
        $statements = $this->buildStatements();
        $dump = implode(DatabaseDumper::STATEMENT_DELIMITER, $statements)
            .DatabaseDumper::STATEMENT_DELIMITER;

        $dumpFile = $this->workdir.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($dumpFile, $dump);
        $size = strlen($dump);

        $this->assertGreaterThan(
            ImportJob::BUDGET_BYTES,
            $size,
            'A fixture precisa cruzar o orçamento do tick, senão o teste não exercita nada.'
        );

        $state = $this->state($dumpFile);

        $ticks = 0;
        do {
            $this->runRestore->invoke($this->job, $state);
            $ticks++;
            $offset = (int) ((array) $state->get('cursor'))['restore_offset'];
            $this->assertLessThan(50, $ticks, 'Restore não convergiu — laço sem progresso.');
        } while ($offset < $size);

        $this->assertGreaterThan(1, $ticks, 'A fixture deveria exigir mais de um tick.');

        /*
         * O PRAGMA de FK vem do próprio job, não do dump — sai da comparação.
         */
        $ran = array_values(array_filter(
            $this->executed,
            fn (string $sql) => ! str_starts_with($sql, 'PRAGMA ')
        ));

        $this->assertSame(
            $statements,
            $ran,
            'Todo statement do dump deve rodar inteiro, uma única vez e em ordem.'
        );
    }

    public function test_pendingBytes_reports_the_unterminated_tail(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('unprepared')->andReturn(true);

        $fed = 'SELECT 1'.DatabaseDumper::STATEMENT_DELIMITER.'SELECT 2 -- sem fim';

        $restorer = new DatabaseRestorer($db);
        $restorer->feed($fed);

        $this->assertSame(strlen($fed), $restorer->pendingBytes(), 'Antes do flush, tudo está pendente.');

        $restorer->executeReady();

        $this->assertSame(
            strlen('SELECT 2 -- sem fim'),
            $restorer->pendingBytes(),
            'A cauda não terminada precisa ser visível para o chamador não avançar o cursor por cima dela.'
        );

        $restorer->finish();
        $this->assertSame(0, $restorer->pendingBytes());
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $path = $dir.DIRECTORY_SEPARATOR.$e;
            is_link($path) ? @unlink($path) : $this->rrmdir($path);
        }
        @rmdir($dir);
    }
}
