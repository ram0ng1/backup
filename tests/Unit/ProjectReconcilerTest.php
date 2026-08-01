<?php

namespace Ramon\Backup\Tests\Unit;

use Flarum\Foundation\Paths;
use PHPUnit\Framework\TestCase;
use Ramon\Backup\Project\ProjectReconciler;

/**
 * Regressão do incidente de produção que motivou esta camada.
 *
 * Um restore sobrescreveu o composer.json vivo com o do backup, que era
 * anterior à instalação do fof/redis. O `composer install` seguinte podou
 * fof/redis, predis e illuminate/redis do vendor/ e reverteu um pin de
 * segurança do guzzle. O extend.php da raiz continuou referenciando
 * `FoF\Redis\Extend\Redis`; como o core dá `require` nesse arquivo dentro
 * de `Site::fromPaths()`, antes de existir handler de erro, o fórum caiu
 * com exit 255, saída vazia e log vazio.
 *
 * Os testes abaixo travam as três propriedades que impedem a repetição:
 * o merge nunca remove require, o lock de outro servidor nunca é gravado,
 * e a classe pendurada é detectada e nomeada.
 */
final class ProjectReconcilerTest extends TestCase
{
    private string $base;
    private string $staging;
    private ProjectReconciler $reconciler;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bkp_pr_'.bin2hex(random_bytes(4));
        $this->staging = $this->base.DIRECTORY_SEPARATOR.'staging';
        @mkdir($this->staging, 0777, true);

        $this->reconciler = new ProjectReconciler(new Paths([
            'base'    => $this->base,
            'public'  => $this->base,
            'storage' => $this->base,
        ]));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function test_merge_keeps_packages_the_backup_never_knew_about(): void
    {
        $this->writeDestManifest([
            'require' => [
                'php'             => '^8.3',
                'flarum/core'     => '^2.0',
                'fof/redis'       => '^1.2',
                'predis/predis'   => '^2.0',
                'guzzlehttp/guzzle' => '^7.9.2',
            ],
        ]);
        $this->writeIncomingManifest([
            'require' => [
                'php'               => '^8.3',
                'flarum/core'       => '^2.0',
                'guzzlehttp/guzzle' => '^7.0',
                'ramon/marketplace' => '^1.0',
            ],
        ]);

        $result = $this->reconciler->reconcile($this->staging, true, false);

        $merged = $this->readDestManifest();

        $this->assertArrayHasKey('fof/redis', $merged['require'], 'fof/redis must survive the restore');
        $this->assertArrayHasKey('predis/predis', $merged['require']);
        $this->assertArrayHasKey('ramon/marketplace', $merged['require'], 'the backup\'s new requires must be added');
        $this->assertSame('^7.9.2', $merged['require']['guzzlehttp/guzzle'], 'a stricter local pin must win over the backup');
        $this->assertContains('composer.json', $result['applied']);
    }

    public function test_lock_from_another_server_is_never_written(): void
    {
        $this->writeDestManifest(['require' => ['flarum/core' => '^2.0']]);
        $this->writeIncomingManifest(['require' => ['flarum/core' => '^2.0']]);
        file_put_contents($this->staging.DIRECTORY_SEPARATOR.'composer.lock', '{"packages":[]}');

        $this->reconciler->reconcile($this->staging, true, false);

        $this->assertFileDoesNotExist(
            $this->base.DIRECTORY_SEPARATOR.'composer.lock',
            'Applying a foreign lock is what prunes packages that only exist here'
        );
    }

    public function test_missing_packages_are_reported_even_when_nothing_is_applied(): void
    {
        $this->writeDestManifest(['require' => ['flarum/core' => '^2.0']]);
        $this->writeIncomingManifest([
            'require' => ['flarum/core' => '^2.0', 'fof/nightmode' => '^1.0'],
        ]);

        $result = $this->reconciler->reconcile($this->staging, false, false);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('fof/nightmode', implode(' ', $result['warnings']));
        $this->assertSame([], $result['applied'], 'apply=false must not touch the manifest');
    }

    public function test_fresh_server_without_a_manifest_gets_both_files_verbatim(): void
    {
        $this->writeIncomingManifest(['require' => ['flarum/core' => '^2.0']]);
        file_put_contents($this->staging.DIRECTORY_SEPARATOR.'composer.lock', '{"packages":[]}');

        $result = $this->reconciler->reconcile($this->staging, true, false);

        $this->assertFileExists($this->base.DIRECTORY_SEPARATOR.'composer.json');
        $this->assertFileExists($this->base.DIRECTORY_SEPARATOR.'composer.lock');
        $this->assertContains('composer.lock', $result['applied']);
    }

    public function test_root_extend_is_only_written_when_opted_in_and_keeps_a_backup(): void
    {
        $original = "<?php\n\nreturn [];\n";
        file_put_contents($this->base.DIRECTORY_SEPARATOR.'extend.php', $original);
        file_put_contents($this->staging.DIRECTORY_SEPARATOR.'extend.php', "<?php\n\nreturn ['from-backup'];\n");

        $this->reconciler->reconcile($this->staging, false, false);
        $this->assertSame($original, file_get_contents($this->base.DIRECTORY_SEPARATOR.'extend.php'));

        $this->reconciler->reconcile($this->staging, false, true);
        $this->assertStringContainsString(
            'from-backup',
            (string) file_get_contents($this->base.DIRECTORY_SEPARATOR.'extend.php')
        );
        $this->assertNotEmpty(
            glob($this->base.DIRECTORY_SEPARATOR.'extend.php.bak-*'),
            'The replaced extend.php must be recoverable'
        );
    }

    public function test_dangling_class_in_root_extend_is_detected(): void
    {
        file_put_contents($this->base.DIRECTORY_SEPARATOR.'extend.php', <<<'PHP'
        <?php

        return [
            (new FoF\Redis\Extend\Redis(['host' => '127.0.0.1']))
                ->useDatabaseWith('cache', 1),
        ];
        PHP);

        $dangling = $this->reconciler->danglingClassReferences();

        $this->assertContains('FoF\Redis\Extend\Redis', $dangling);
    }

    public function test_resolvable_classes_are_not_reported(): void
    {
        file_put_contents($this->base.DIRECTORY_SEPARATOR.'extend.php', <<<'PHP'
        <?php

        use Ramon\Backup\Project\ProjectReconciler;

        return [
            new ProjectReconciler(new \Flarum\Foundation\Paths([])),
        ];
        PHP);

        $this->assertSame([], $this->reconciler->danglingClassReferences());
    }

    /** @param array<string, mixed> $manifest */
    private function writeDestManifest(array $manifest): void
    {
        file_put_contents(
            $this->base.DIRECTORY_SEPARATOR.'composer.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    /** @param array<string, mixed> $manifest */
    private function writeIncomingManifest(array $manifest): void
    {
        file_put_contents(
            $this->staging.DIRECTORY_SEPARATOR.'composer.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    /** @return array<string, mixed> */
    private function readDestManifest(): array
    {
        $raw = (string) file_get_contents($this->base.DIRECTORY_SEPARATOR.'composer.json');
        return (array) json_decode($raw, true);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $this->rrmdir($dir.DIRECTORY_SEPARATOR.$e);
        }
        @rmdir($dir);
    }
}
