<?php

namespace Ramon\Backup\Api\Controller;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Extensions\Inventory;

/**
 * GET /api/backup/extensions
 *
 * Lists every extension installed on this Flarum, with the location
 * tag the export modal renders ("workbench" / "vendor"). The admin
 * picks which extensions to bundle on the export side.
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
