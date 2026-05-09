<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;

/**
 * Streams a saved backup file to the browser. Path resolution goes
 * through `StoragePaths::backupFilePath()`, which whitelists the
 * filename pattern and verifies the resolved absolute path lives
 * under the canonical backups directory — so a malicious id that
 * resolves to a row with a tampered filename can't escape.
 */
class DownloadBackupController implements RequestHandlerInterface
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

        if (! $backup) {
            throw new ValidationException(['id' => 'Backup not found.']);
        }

        $abs = $this->paths->backupFilePath($backup->filename);
        if ($abs === null) {
            throw new ValidationException([
                'file' => 'Backup file path could not be resolved (filename in DB: "'.$backup->filename.'").',
            ]);
        }
        if (! is_file($abs)) {
            throw new ValidationException([
                'file' => 'Backup file is missing on disk: '.$abs,
            ]);
        }

        $fh = fopen($abs, 'rb');
        if ($fh === false) {
            throw new ValidationException(['file' => 'Could not open backup file.']);
        }

        return (new Response(new Stream($fh)))
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) filesize($abs))
            ->withHeader('Content-Disposition', 'attachment; filename="'.$backup->filename.'"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');
    }
}
