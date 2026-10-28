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
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Quem pode chamar as rotas do backup.
 */
class AccessTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-backup');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            'lista'       => ['GET', '/api/backup/backups'],
            'download'    => ['GET', '/api/backup/backups/1/download'],
            'apagar'      => ['DELETE', '/api/backup/backups/1'],
            'exportar'    => ['POST', '/api/backup/exports'],
            'importar'    => ['POST', '/api/backup/imports'],
            'chunk'       => ['POST', '/api/backup/imports/0123456789abcdef/chunk'],
            'iniciar'     => ['POST', '/api/backup/imports/0123456789abcdef/start'],
            'status'      => ['GET', '/api/backup/encryption/status'],
            'gerar chave' => ['POST', '/api/backup/encryption/generate-keypair'],
            'extensões'   => ['GET', '/api/backup/extensions'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function readRoutes(): array
    {
        // Escrita de visitante já para no CSRF; as leituras são as que importam.
        return array_filter(self::routes(), fn (array $route) => $route[0] === 'GET');
    }

    #[DataProvider('readRoutes')]
    public function test_visitante_nao_acessa(string $method, string $path): void
    {
        $response = $this->send($this->request($method, $path, ['json' => []]));

        $this->assertContains($response->getStatusCode(), [401, 403], (string) $response->getBody());
    }

    #[DataProvider('routes')]
    public function test_membro_nao_acessa(string $method, string $path): void
    {
        $response = $this->send($this->request($method, $path, ['authenticatedAs' => 2, 'json' => []]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_admin_lista_backups_e_extensoes(): void
    {
        $list = $this->send($this->request('GET', '/api/backup/backups', ['authenticatedAs' => 1]));
        $this->assertEquals(200, $list->getStatusCode(), (string) $list->getBody());
        $this->assertSame(['backups' => []], json_decode((string) $list->getBody(), true));

        $extensions = $this->send($this->request('GET', '/api/backup/extensions', ['authenticatedAs' => 1]));
        $this->assertEquals(200, $extensions->getStatusCode(), (string) $extensions->getBody());
        $this->assertIsArray(json_decode((string) $extensions->getBody(), true)['extensions']);
    }

    public function test_admin_le_o_status_da_criptografia(): void
    {
        $response = $this->send($this->request('GET', '/api/backup/encryption/status', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());
        $status = json_decode((string) $response->getBody(), true);
        $this->assertFalse($status['has_public_key']);
        $this->assertSame('backup-private-key', $status['config_key']);
    }
}
