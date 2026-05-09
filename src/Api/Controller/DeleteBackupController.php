<?php

namespace Ramon\Backup\Api\Controller;

use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;

/**
 * Admin deletes a saved backup. Removes both the row and the file on
 * disk; if the file is already gone we just delete the row, since a
 * dangling row is the only thing that would surface the missing file
 * in the UI.
 */
class DeleteBackupController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected StoragePaths $paths
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $backup = Backup::query()->find($id);

        if ($backup) {
            $abs = $this->paths->backupFilePath($backup->filename);
            if ($abs !== null) {
                @unlink($abs);
            }
            $backup->delete();
        }

        return new EmptyResponse(204);
    }
}
