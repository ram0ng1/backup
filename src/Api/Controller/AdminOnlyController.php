<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tiny mixin for "admin / backup-manager only" controllers. Keeps the
 * permission check uniform across every endpoint in the extension.
 */
trait AdminOnlyController
{
    protected function assertCanManage(ServerRequestInterface $request): void
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! $actor->isAdmin() && ! $actor->hasPermission('backup.manage')) {
            throw new PermissionDeniedException();
        }
    }

    /**
     * Restaurar troca o banco inteiro, inclusive quem é admin, e a chave
     * gerada decide quem consegue ler os backups cifrados: nada disso pode
     * ficar ao alcance de um grupo que só recebeu `backup.manage`.
     */
    protected function assertAdmin(ServerRequestInterface $request): void
    {
        RequestUtil::getActor($request)->assertAdmin();
    }
}
