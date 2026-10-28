<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Laminas\Diactoros\StreamFactory;
use Ramon\Backup\Archive\Format;

/**
 * O cabeçalho de um `.flarum` vem de fora (o arquivo viaja entre
 * servidores) e o modal de importação o mostra antes de qualquer
 * confirmação.
 */
class InspectImportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-backup');
    }

    /** @param array<string, mixed> $meta */
    private function archive(array $meta): string
    {
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES);

        return Format::MAGIC.chr(0).pack('N', strlen($json)).$json.pack('N', 0);
    }

    /** @return array<string, mixed> */
    private function inspect(string $bytes): array
    {
        $init = $this->send($this->request('POST', '/api/backup/imports', [
            'authenticatedAs' => 1,
            'json' => ['filename' => 'x.flarum', 'size' => strlen($bytes)],
        ]));
        $this->assertEquals(200, $init->getStatusCode(), (string) $init->getBody());
        $jobId = json_decode((string) $init->getBody(), true)['job_id'];

        $chunk = $this->send(
            $this->request('POST', "/api/backup/imports/$jobId/chunk", ['authenticatedAs' => 1])
                ->withHeader('X-Chunk-Offset', '0')
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withBody((new StreamFactory())->createStream($bytes))
        );
        $this->assertEquals(200, $chunk->getStatusCode(), (string) $chunk->getBody());

        $inspect = $this->send($this->request('POST', "/api/backup/imports/$jobId/inspect", ['authenticatedAs' => 1, 'json' => []]));
        $this->assertEquals(200, $inspect->getStatusCode(), (string) $inspect->getBody());

        $this->send($this->request('DELETE', "/api/backup/imports/$jobId", ['authenticatedAs' => 1]));

        return json_decode((string) $inspect->getBody(), true);
    }

    public function test_campos_do_cabecalho_chegam_como_texto(): void
    {
        $vnode = ['tag' => '<', 'children' => '<img src=x onerror=alert(1)>'];

        $body = $this->inspect($this->archive([
            'format_version' => 3,
            'created_at'     => $vnode,
            'flarum_version' => $vnode,
            'source_url'     => $vnode,
            'contents'       => ['db', $vnode],
            'manifest'       => [
                'extension_count' => $vnode,
                'extensions'      => [
                    null,
                    'legacy-dir',
                    ['id' => 'acme-x', 'name' => $vnode, 'title' => $vnode, 'version' => '1.0', 'location' => 'x" onclick="y'],
                    ['title' => 'sem id'],
                ],
            ],
        ]));

        $meta = $body['meta'];
        foreach (['created_at', 'flarum_version', 'source_url'] as $key) {
            $this->assertArrayNotHasKey($key, $meta, "$key não é texto e não deveria chegar à interface");
        }
        $this->assertSame(['db'], $meta['contents']);
        $this->assertSame(0, $meta['manifest']['extension_count']);
        $this->assertSame([
            ['id' => 'legacy-dir', 'name' => '', 'title' => 'legacy-dir', 'version' => '', 'location' => 'workbench'],
            ['id' => 'acme-x', 'name' => '', 'title' => 'acme-x', 'version' => '1.0', 'location' => 'unknown'],
        ], $meta['manifest']['extensions']);
    }

    public function test_cabecalho_legitimo_passa_inteiro(): void
    {
        $body = $this->inspect($this->archive([
            'format_version' => 3,
            'created_at'     => '2026-10-28T12:00:00+00:00',
            'flarum_version' => 'v2.0.0',
            'php_version'    => PHP_VERSION,
            'source_url'     => 'https://forum.example.com',
            'contents'       => ['db', 'assets', 'extensions'],
            'manifest'       => [
                'asset_count'     => 3,
                'storage_count'   => 0,
                'extension_count' => 12,
                'has_composer'    => true,
                'has_root_extend' => false,
                'extensions'      => [
                    ['id' => 'acme-x', 'name' => 'acme/x', 'title' => 'Acme X', 'version' => '1.2.3', 'location' => 'vendor', 'relative' => 'vendor/acme/x', 'files' => 12, 'bytes' => 3400],
                ],
            ],
        ]));

        $meta = $body['meta'];
        $this->assertFalse($body['is_encrypted']);
        $this->assertSame('2026-10-28T12:00:00+00:00', $meta['created_at']);
        $this->assertSame('v2.0.0', $meta['flarum_version']);
        $this->assertSame('https://forum.example.com', $meta['source_url']);
        $this->assertSame(['db', 'assets', 'extensions'], $meta['contents']);
        $this->assertSame(3, $meta['manifest']['asset_count']);
        $this->assertSame(12, $meta['manifest']['extension_count']);
        $this->assertTrue($meta['manifest']['has_composer']);
        $this->assertSame(
            [['id' => 'acme-x', 'name' => 'acme/x', 'title' => 'Acme X', 'version' => '1.2.3', 'location' => 'vendor']],
            $meta['manifest']['extensions']
        );
    }
}
