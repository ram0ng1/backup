<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Api\Controller;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Extensions\Inventory;

/**
 * GET /api/backup/extensions
 *
 * Extensões instaladas, com a origem ("workbench" / "vendor") que o modal
 * de exportação mostra para o admin escolher o que empacotar.
 */
class ListExtensionsController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected Inventory $inventory
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);
        return new JsonResponse(['extensions' => $this->inventory->list()]);
    }
}
