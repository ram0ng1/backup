<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Archive\ArchiveReader;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports
 *
 * Accepts an uploaded `.flarum` archive (multipart). Stages it under
 * the per-job tmp dir as `upload.flarum`, reads the header WITHOUT
 * decrypting, and returns:
 *   - job_id
 *   - is_encrypted (so the UI can prompt for the private key)
 *   - meta (creation date, contents, format version)
 *
 * The actual restore is kicked off by /api/backup/imports/{id}/start.
 *
 * Note on chunked uploads: most PHP / nginx setups cap upload size.
 * We don't try to be cleverer than the server here — if the upload
 * fails, the operator should raise upload_max_filesize or use the
 * import-from-existing-backup-id flow.
 */
class UploadImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected StoragePaths $paths
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $files = $request->getUploadedFiles();
        $upload = $files['archive'] ?? null;
        if ($upload === null || $upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['archive' => 'No archive uploaded.']);
        }

        $clientName = (string) $upload->getClientFilename();
        if ($clientName !== '' && ! str_ends_with(strtolower($clientName), '.flarum')) {
            throw new ValidationException(['archive' => 'File must have a .flarum extension.']);
        }

        $jobId = bin2hex(random_bytes(8));
        $dir = $this->paths->importJobDir($jobId);
        $dest = $dir.DIRECTORY_SEPARATOR.'upload.flarum';

        $upload->moveTo($dest);

        // Validate it's actually a Flarum backup before we tell the
        // user "ready to restore". We open ONLY the header — no
        // decryption, no key required at this stage.
        try {
            $reader = ArchiveReader::openHeader($dest);
            $isEncrypted = $reader->isEncrypted();
            $meta = $reader->meta();
            $reader->close();
        } catch (\Throwable $e) {
            $this->paths->deleteDir($dir);
            throw new ValidationException(['archive' => 'Not a valid Flarum backup: '.$e->getMessage()]);
        }

        return new JsonResponse([
            'job_id'       => $jobId,
            'is_encrypted' => $isEncrypted,
            'meta'         => $meta,
            'size'         => filesize($dest) ?: 0,
        ]);
    }
}
