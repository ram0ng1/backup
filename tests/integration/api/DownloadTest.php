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

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ramon\Backup\StoragePaths;

/**
 * O download devolve uma resposta PSR-7 que o Emitter do Flarum transmite
 * em pedaços (CallbackStream), com suporte a Range. Antes ele escrevia na
 * saída e chamava `exit`, o que nem dava para testar: encerrava o PHPUnit.
 */
class DownloadTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const CONTENT = "FLARUMBK-conteudo-de-teste-0123456789";

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-backup');

        $this->prepareDatabase([
            'backups' => [[
                'id' => 1, 'filename' => '1-teste.flarum', 'size_bytes' => strlen(self::CONTENT),
                'encrypted' => 0, 'contents' => 'db', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]],
        ]);
    }

    private function writeBackupFile(): void
    {
        $dir = $this->app()->getContainer()->make(StoragePaths::class)->backupsDir();
        file_put_contents($dir.DIRECTORY_SEPARATOR.'1-teste.flarum', self::CONTENT);
    }

    private function body(ResponseInterface $response): string
    {
        // CallbackStream é o tipo que o Emitter do Flarum transmite em pedaços.
        $this->assertInstanceOf(\Laminas\Diactoros\CallbackStream::class, $response->getBody());

        return (string) $response->getBody();
    }

    private function download(array $headers = []): ResponseInterface
    {
        $request = $this->request('GET', '/api/backup/backups/1/download', ['authenticatedAs' => 1]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->send($request);
    }

    public function test_baixa_o_arquivo_inteiro(): void
    {
        $this->writeBackupFile();

        $response = $this->download();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame((string) strlen(self::CONTENT), $response->getHeaderLine('Content-Length'));
        $this->assertStringContainsString('filename="1-teste.flarum"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertSame(self::CONTENT, $this->body($response));
    }

    public function test_retoma_por_faixa(): void
    {
        $this->writeBackupFile();

        $response = $this->download(['Range' => 'bytes=9-16']);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 9-16/'.strlen(self::CONTENT), $response->getHeaderLine('Content-Range'));
        $this->assertSame('8', $response->getHeaderLine('Content-Length'));
        $this->assertSame(substr(self::CONTENT, 9, 8), $this->body($response));
    }

    public function test_faixa_final(): void
    {
        $this->writeBackupFile();

        $response = $this->download(['Range' => 'bytes=-4']);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('6789', $this->body($response));
    }
}
