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

use Flarum\Foundation\Paths;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Laminas\Diactoros\StreamFactory;

/**
 * Upload em pedaços: cada pedaço só pode cair dentro do tamanho que o
 * upload declarou no começo.
 */
class ChunkUploadTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-backup');
    }

    private function init(int $size): string
    {
        $response = $this->send($this->request('POST', '/api/backup/imports', [
            'authenticatedAs' => 1,
            'json' => ['filename' => 'x.flarum', 'size' => $size],
        ]));

        return json_decode((string) $response->getBody(), true)['job_id'];
    }

    private function chunk(string $jobId, int $offset, string $bytes): \Psr\Http\Message\ResponseInterface
    {
        return $this->send(
            $this->request('POST', "/api/backup/imports/$jobId/chunk", ['authenticatedAs' => 1])
                ->withHeader('X-Chunk-Offset', (string) $offset)
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withBody((new StreamFactory())->createStream($bytes))
        );
    }

    private function staged(string $jobId): string
    {
        $storage = $this->app()->getContainer()->make(Paths::class)->storage;

        return $storage."/backup-tmp/import-$jobId/upload.flarum";
    }

    public function test_offset_alem_do_tamanho_declarado_e_recusado_sem_gravar(): void
    {
        $jobId = $this->init(1000);

        $response = $this->chunk($jobId, 20 * 1024 * 1024, '');

        $this->assertEquals(422, $response->getStatusCode(), (string) $response->getBody());
        clearstatcache();
        $this->assertSame(0, filesize($this->staged($jobId)));

        $this->send($this->request('DELETE', "/api/backup/imports/$jobId", ['authenticatedAs' => 1]));
    }

    public function test_pedacos_dentro_do_tamanho_sao_gravados(): void
    {
        $jobId = $this->init(10);

        $first = $this->chunk($jobId, 0, '01234');
        $second = $this->chunk($jobId, 5, '56789');

        $this->assertEquals(200, $first->getStatusCode(), (string) $first->getBody());
        $this->assertSame(['received' => 10, 'expected' => 10], json_decode((string) $second->getBody(), true));
        $this->assertSame('0123456789', file_get_contents($this->staged($jobId))); /* arquivo de staging do teste, sem URL; nosemgrep: flarum-v2-server-side-fetch */

        $this->send($this->request('DELETE', "/api/backup/imports/$jobId", ['authenticatedAs' => 1]));
    }
}
