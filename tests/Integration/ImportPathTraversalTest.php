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
 * §13.9 regression guard for the archive-extraction path. A `.flarum`
 * archive is, by design, restored from an untrusted/foreign source, so
 * the entry-name → absolute-path resolver must refuse anything that
 * escapes the whitelisted roots (assets/, storage/, a known extension
 * dir, project/composer.{json,lock}). Covers literal traversal, encoded
 * variants, absolute paths, backslashes, NUL, a hostile manifest
 * `relative`, and (best-effort) a symlinked component.
 */
final class ImportPathTraversalTest extends TestCase
{
    private string $workdir;
    private string $jobDir;
    private ImportJob $job;
    private ReflectionMethod $resolve;
    private ReflectionMethod $extMap;

    protected function setUp(): void
    {
        $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_pt_'.bin2hex(random_bytes(4));
        foreach (['', '/public', '/public/assets', '/storage', '/workbench', '/vendor', '/jobdir'] as $sub) {
            @mkdir($this->workdir.$sub, 0777, true);
        }
        $this->jobDir = $this->workdir.DIRECTORY_SEPARATOR.'jobdir';

        $paths = new Paths([
            'base'    => $this->workdir,
            'public'  => $this->workdir.DIRECTORY_SEPARATOR.'public',
            'storage' => $this->workdir.DIRECTORY_SEPARATOR.'storage',
        ]);
        $config = new Config(['url' => 'http://localhost']);

        $settings = Mockery::mock(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn('');
        $cipher = new BackupCipher($settings, $config);

        // resolveDestination never touches the DB, so a bare mock that
        // satisfies the (narrowed) Connection type is enough.
        $db = Mockery::mock(Connection::class);

        $this->job = new ImportJob(
            new StoragePaths($paths),
            $paths,
            $db,
            $cipher,
            $config,
            new \Ramon\Backup\Project\ProjectReconciler($paths),
            new \Ramon\Backup\Settings\SettingsPreserver($settings)
        );

        // PHP 8.1+ reflection reaches private methods without
        // setAccessible() (which is a deprecated no-op since 8.5).
        $this->resolve = new ReflectionMethod($this->job, 'resolveDestination');
        $this->extMap = new ReflectionMethod($this->job, 'extensionDestinationMap');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->rrmdir($this->workdir);
    }

    private function state(array $data = []): JobState
    {
        $file = $this->workdir.DIRECTORY_SEPARATOR.'job_'.bin2hex(random_bytes(3)).'.json';

        // `project/*` resolve para um staging dentro do diretório do job,
        // então o state precisa carregar `paths.dir` como o job real faz.
        $data['paths'] = $data['paths'] ?? ['dir' => $this->jobDir];

        return JobState::create($file, $data);
    }

    private function resolve(string $name, ?JobState $state = null): ?string
    {
        return $this->resolve->invoke($this->job, $name, $state ?? $this->state());
    }

    /** @dataProvider traversalProvider */
    public function test_resolveDestination_rejects_escapes(string $name): void
    {
        $this->assertNull($this->resolve($name), "Should refuse to resolve: $name");
    }

    public static function traversalProvider(): array
    {
        return [
            'parent dir'             => ['assets/../../etc/passwd'],
            'deep parent'            => ['storage/../../../config.php'],
            'backslash traversal'    => ['assets/..\\..\\boot.ini'],
            'lone backslash'         => ['assets\\evil'],
            'absolute unix'          => ['/etc/passwd'],
            'nul byte'               => ["assets/x\0.png"],
            'extensions parent'      => ['extensions/../../config.php'],
            'unknown root'           => ['etc/passwd'],
            'project non-composer'   => ['project/.htaccess'],
            'project traversal'      => ['project/../composer.json'],
            'dotdot encoded segment' => ['assets/....//x'],
        ];
    }

    public function test_resolveDestination_accepts_legit_paths(): void
    {
        $this->assertNotNull($this->resolve('assets/avatars/1.png'));
        $this->assertNotNull($this->resolve('storage/uploads/a.txt'));
        $this->assertNotNull($this->resolve('project/composer.json'));
        $this->assertNotNull($this->resolve('project/extend.php'));
    }

    /**
     * Regressão do incidente que motivou o ProjectReconciler: um restore
     * sobrescreveu o composer.json vivo com o do backup, o composer
     * install seguinte podou o pacote, e o extend.php da raiz ficou
     * apontando para uma classe inexistente — fatal antes do handler de
     * erro. `project/*` nunca mais pode resolver para a raiz do install.
     */
    public function test_project_entries_land_in_staging_not_the_install_root(): void
    {
        foreach (['composer.json', 'composer.lock', 'extend.php'] as $name) {
            $resolved = $this->resolve('project/'.$name);

            $this->assertNotNull($resolved, "project/$name should resolve");
            $this->assertStringStartsWith(
                $this->jobDir,
                (string) $resolved,
                "project/$name must stage inside the job dir"
            );
            $this->assertNotSame(
                $this->workdir.DIRECTORY_SEPARATOR.$name,
                $resolved,
                "project/$name must never be written straight to the install root"
            );
        }
    }

    public function test_extensionDestinationMap_rejects_hostile_relative(): void
    {
        $state = $this->state(['archive_meta' => ['manifest' => ['extensions' => [
            ['id' => 'evil-parent', 'relative' => '../../etc'],
            ['id' => 'evil-abs',    'relative' => '/etc'],
            ['id' => 'evil-bslash', 'relative' => 'workbench\\x'],
            ['id' => 'evil-dot',    'relative' => 'workbench/..'],
            ['id' => 'good-wb',     'relative' => 'workbench/my-ext'],
            ['id' => 'good-vendor', 'relative' => 'vendor/acme/widget'],
        ]]]]);

        $map = $this->extMap->invoke($this->job, $state);

        // Hostile entries are dropped entirely.
        $this->assertArrayNotHasKey('evil-parent', $map);
        $this->assertArrayNotHasKey('evil-abs', $map);
        $this->assertArrayNotHasKey('evil-bslash', $map);
        $this->assertArrayNotHasKey('evil-dot', $map);

        // Legitimate layouts are kept and stay under the install base.
        $this->assertArrayHasKey('good-wb', $map);
        $this->assertArrayHasKey('good-vendor', $map);
        $this->assertStringStartsWith($this->workdir, $map['good-wb']);
        $this->assertStringStartsWith($this->workdir, $map['good-vendor']);
    }

    public function test_resolveDestination_rejects_symlink_escape(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_pt_out_'.bin2hex(random_bytes(4));
        @mkdir($outside, 0777, true);
        $link = $this->workdir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'link';

        if (! @symlink($outside, $link)) {
            $this->rrmdir($outside);
            $this->markTestSkipped('Cannot create symlinks in this environment');
        }

        // "storage/link/evil" has no literal traversal, but `link` is a
        // symlink out of the install — the realpath confinement must
        // still refuse it.
        $resolved = $this->resolve('storage/link/evil.txt');

        @unlink($link);
        $this->rrmdir($outside);

        $this->assertNull($resolved, 'Symlinked component must be refused by realpath confinement');
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
