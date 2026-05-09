<?php

namespace Ramon\Backup\Api\Controller;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Models\Backup;

/**
 * Returns every saved backup, newest first. Cheap query — the admin UI
 * shows them all on one screen rather than paginating, since the row
 * count is bounded by how often anyone clicks "create backup".
 */
class ListBackupsController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $rows = Backup::query()
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get();

        $data = $rows->map(fn (Backup $b) => [
            'id'             => (int) $b->id,
            'filename'       => $b->filename,
            'size_bytes'     => (int) $b->size_bytes,
            'encrypted'      => (bool) $b->encrypted,
            'contents'       => $b->contentsList(),
            'flarum_version' => $b->flarum_version,
            'php_version'    => $b->php_version,
            'created_at'     => optional($b->created_at)->toIso8601String(),
            'created_by'     => $b->created_by ? (int) $b->created_by : null,
        ])->all();

        return new JsonResponse(['backups' => $data]);
    }
}
