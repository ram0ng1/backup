<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Archive\ArchiveReader;
use Ramon\Backup\Environment\StackSnapshot;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports/{id}/inspect
 *
 * Final step of the chunked upload. The staging file should now be
 * complete (size == expected_size from init). We open the archive
 * header WITHOUT decrypting, return is_encrypted + meta, and let the
 * UI decide whether to ask for a private key. The actual restore is
 * still kicked off by /backup/imports/{id}/start.
 *
 * If the staging file is short of the declared size, we refuse —
 * the caller should retry the missing chunks first. Returning a
 * partial inspect would mislead the UI.
 */
class InspectImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected StoragePaths $paths
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $jobId = (string) ($request->getQueryParams()['id'] ?? '');
        if (! preg_match('/^[a-f0-9]{16}$/', $jobId)) {
            throw new ValidationException(['id' => 'Invalid job id.']);
        }

        $dir  = $this->paths->importJobDir($jobId);
        $dest = $dir.DIRECTORY_SEPARATOR.'upload.flarum';

        if (! is_file($dest)) {
            throw new ValidationException([
                'archive' => 'Upload session not initialised or already cleaned up.',
            ]);
        }

        $meta = $this->loadMeta($dir);
        $expected = (int) ($meta['expected_size'] ?? 0);
        $actual   = filesize($dest) ?: 0;

        if ($expected > 0 && $actual < $expected) {
            throw new ValidationException([
                'archive' => sprintf(
                    'Upload incomplete: %d / %d bytes received. Re-send missing chunks before inspect.',
                    $actual, $expected
                ),
            ]);
        }

        try {
            $reader = ArchiveReader::openHeader($dest);
            $isEncrypted = $reader->isEncrypted();
            $archiveMeta = $reader->meta();
            $reader->close();
        } catch (\Throwable $e) {
            $this->paths->deleteDir($dir);
            throw new ValidationException([
                'archive' => 'Not a valid Flarum backup: '.$e->getMessage(),
            ]);
        }

        // Stack gate at inspect time — the operator learns the restore
        // is impossible BEFORE ticking "confirm replace", instead of
        // after committing. ImportJob re-checks it as the authoritative
        // backstop for the CLI and for direct API callers that skip
        // inspect. The staged upload is left in place so a retry after
        // a PHP upgrade doesn't need a re-upload; PruneStaleJobsCommand
        // sweeps it if the operator walks away.
        $blocking = StackSnapshot::blockingReason($archiveMeta);
        if ($blocking !== null) {
            throw new ValidationException(['archive' => $blocking]);
        }

        return new JsonResponse([
            'job_id'       => $jobId,
            'is_encrypted' => $isEncrypted,
            'meta'         => $archiveMeta,
            'size'         => $actual,
            'advisories'   => StackSnapshot::advisories($archiveMeta),
        ]);
    }

    /** @return array{expected_size?: int, filename?: string} */
    private function loadMeta(string $dir): array
    {
        $path = $dir.DIRECTORY_SEPARATOR.'upload.meta.json';
        if (! is_file($path)) return [];
        $raw = @file_get_contents($path); /* leitura de arquivo local, sem URL de input; nosemgrep: flarum-v2-server-side-fetch */
        if ($raw === false) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
