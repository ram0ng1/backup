<?php

namespace Ramon\Backup\Tests\Integration;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Crypto\BackupCipher;
use Ramon\Backup\Job\ImportJob;
use Ramon\Backup\Job\JobState;
use Ramon\Backup\StoragePaths;
use ReflectionMethod;

/**
 * Guarda de regressão para o destino que o disco recusa. Um `vendor/`
 * pertencente a root com o PHP rodando como www-data fazia o extract
 * lançar no primeiro arquivo de extensão — e a restauração inteira
 * morria sem gravar banco, assets nem storage. O destino recusado agora
 * é contado, amostrado com o motivo do SO, e a restauração segue.
 */
final class ImportUnwritableDestinationTest extends TestCase
{
    private string $workdir;
    private ImportJob $job;
    private ReflectionMethod $openDestination;
    private ReflectionMethod $nearestAncestor;
    private ReflectionMethod $preflight;

    protected function setUp(): void
    {
        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_uw_'.bin2hex(random_bytes(4));
        foreach (['', '/public', '/public/assets', '/storage', '/vendor', '/jobdir'] as $sub) {
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

        $this->job = new ImportJob(
            new StoragePaths($paths),
            $paths,
            Mockery::mock(Connection::class),
            new BackupCipher($settings, $config),
            $config,
            new \Ramon\Backup\Project\ProjectReconciler($paths),
            new \Ramon\Backup\Settings\SettingsPreserver($settings)
        );

        $this->openDestination = new ReflectionMethod($this->job, 'openDestination');
        $this->nearestAncestor = new ReflectionMethod($this->job, 'nearestExistingAncestor');
        $this->preflight = new ReflectionMethod($this->job, 'preflightWritability');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->rrmdir($this->workdir);
    }

    private function state(array $data = []): JobState
    {
        $file = $this->workdir.DIRECTORY_SEPARATOR.'job_'.bin2hex(random_bytes(3)).'.json';
        $data['paths'] = $data['paths'] ?? ['dir' => $this->workdir.DIRECTORY_SEPARATOR.'jobdir'];

        return JobState::create($file, $data);
    }

    /**
     * O caso que motivou a mudança: destino impossível devolve null com o
     * motivo, em vez de lançar. Um componente de caminho que é ARQUIVO
     * derruba mkdir e fopen em qualquer plataforma, sem depender de chmod
     * (que é no-op no Windows).
     */
    public function test_openDestination_returns_null_with_reason_when_write_is_impossible(): void
    {
        $blocker = $this->workdir.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'blocker';
        file_put_contents($blocker, 'não sou um diretório');

        $reason = null;
        $fh = $this->openDestination->invokeArgs(
            $this->job,
            [$blocker.DIRECTORY_SEPARATOR.'pkg'.DIRECTORY_SEPARATOR.'.gitignore', &$reason]
        );

        $this->assertNull($fh, 'Um destino irrecuperável deve devolver null, não lançar.');
        $this->assertIsString($reason);
        $this->assertNotSame('', $reason, 'O motivo do SO precisa chegar ao operador.');
    }

    public function test_openDestination_creates_missing_directories_and_writes(): void
    {
        $dest = $this->workdir.DIRECTORY_SEPARATOR.'vendor'
            .DIRECTORY_SEPARATOR.'forumaker'.DIRECTORY_SEPARATOR.'profile-cover'
            .DIRECTORY_SEPARATOR.'.gitignore';

        $reason = null;
        $fh = $this->openDestination->invokeArgs($this->job, [$dest, &$reason]);

        $this->assertIsResource($fh);
        $this->assertNull($reason);
        fwrite($fh, "/vendor\n");
        fclose($fh);
        $this->assertStringEqualsFile($dest, "/vendor\n");
    }

    public function test_nearestExistingAncestor_walks_up_to_the_first_real_directory(): void
    {
        $vendor = $this->workdir.DIRECTORY_SEPARATOR.'vendor';
        $deep = $vendor.DIRECTORY_SEPARATOR.'fof'.DIRECTORY_SEPARATOR.'user-bio';

        $this->assertSame($vendor, $this->nearestAncestor->invoke($this->job, $deep));
        $this->assertSame($vendor, $this->nearestAncestor->invoke($this->job, $vendor));
    }

    public function test_preflight_stays_silent_when_every_root_is_writable(): void
    {
        $state = $this->state([
            'options'      => ['selection' => ['db' => true, 'assets' => true, 'storage' => true, 'extensions' => true]],
            'archive_meta' => ['manifest' => ['extensions' => [
                ['id' => 'fof-user-bio', 'relative' => 'vendor/fof/user-bio'],
            ]]],
            'warnings' => [],
        ]);

        $this->preflight->invoke($this->job, $state);

        $this->assertSame([], (array) $state->get('warnings'));
    }

    public function test_preflight_warns_when_an_extension_root_refuses_writes(): void
    {
        $vendor = $this->workdir.DIRECTORY_SEPARATOR.'vendor';
        @chmod($vendor, 0555);
        if (is_writable($vendor)) {
            @chmod($vendor, 0777);
            $this->markTestSkipped('chmod não tem efeito aqui (Windows ou execução como root).');
        }

        $state = $this->state([
            'options'      => ['selection' => ['db' => true, 'assets' => false, 'storage' => false, 'extensions' => true]],
            'archive_meta' => ['manifest' => ['extensions' => [
                ['id' => 'fof-user-bio', 'relative' => 'vendor/fof/user-bio'],
            ]]],
            'warnings' => [],
        ]);

        $this->preflight->invoke($this->job, $state);
        @chmod($vendor, 0777);

        $warnings = (array) $state->get('warnings');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($vendor, (string) $warnings[0]);
        $this->assertStringContainsString('permissão de escrita', (string) $warnings[0]);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            @unlink($dir);
            return;
        }
        @chmod($dir, 0777);
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $path = $dir.DIRECTORY_SEPARATOR.$e;
            is_link($path) ? @unlink($path) : $this->rrmdir($path);
        }
        @rmdir($dir);
    }
}
