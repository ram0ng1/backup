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

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `backup.manage` pode ser dada a um grupo que não é admin. Restaurar
 * troca o banco inteiro (inclusive quem é admin) e a chave nova decide
 * quem lê os backups cifrados, então essas rotas continuam só do admin.
 */
class ManagePermissionTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-backup');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'group_permission' => [
                ['permission' => 'backup.manage', 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    public function test_quem_tem_a_permissao_lista_os_backups(): void
    {
        $response = $this->send($this->request('GET', '/api/backup/backups', ['authenticatedAs' => 2]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function adminOnly(): array
    {
        return [
            'enviar arquivo'  => ['POST', '/api/backup/imports', ['filename' => 'x.flarum', 'size' => 10]],
            'chunk'           => ['POST', '/api/backup/imports/0123456789abcdef/chunk', []],
            'inspecionar'     => ['POST', '/api/backup/imports/0123456789abcdef/inspect', []],
            'restaurar'       => ['POST', '/api/backup/imports/0123456789abcdef/start', ['confirm_replace' => true]],
            'tick do restore' => ['POST', '/api/backup/imports/0123456789abcdef/tick', []],
            'cancelar'        => ['DELETE', '/api/backup/imports/0123456789abcdef', []],
            'gerar chave'     => ['POST', '/api/backup/encryption/generate-keypair', ['acknowledge_loss' => true]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('adminOnly')]
    public function test_restaurar_e_gerar_chave_continuam_so_do_admin(string $method, string $path, array $body): void
    {
        $response = $this->send($this->request($method, $path, ['authenticatedAs' => 2, 'json' => $body]));

        $this->assertEquals(403, $response->getStatusCode(), (string) $response->getBody());
    }
}
