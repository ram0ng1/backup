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

use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;

/**
 * Apaga o backup e o arquivo em disco. Se o arquivo já sumiu, apaga só a
 * linha, que de outro modo ficaria órfã na lista.
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
