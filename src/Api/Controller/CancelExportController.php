<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\StoragePaths;

/**
 * DELETE /api/backup/exports/{id}
 *
 * User cancelled or dismissed the export modal. Wipes the per-job tmp
 * dir entirely — partial archive, dump.sql, manifest, state file.
 */
class CancelExportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected StoragePaths $paths
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $id = (string) ($request->getQueryParams()['id'] ?? '');
        if (! preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new ValidationException(['id' => 'Invalid job id.']);
        }

        $dir = $this->paths->exportJobDir($id);
        $this->paths->deleteDir($dir);

        return new EmptyResponse(204);
    }
}
