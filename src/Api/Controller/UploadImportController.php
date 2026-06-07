<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports
 *
 * Initialise a chunked upload. Body: `{ "filename": "...flarum",
 * "size": 1234567890 }`.
 *
 * Why chunked instead of one big multipart POST: a single multipart
 * upload of a multi-GB `.flarum` archive routinely fails with a 500
 * on real servers because every layer in front of PHP has its own
 * size cap (`upload_max_filesize` / `post_max_size` / nginx
 * `client_max_body_size` / `memory_limit` while parsing multipart
 * boundaries). Splitting the file into ~4 MB chunks side-steps every
 * one of those — each chunk request is well below any default cap,
 * and a flaky network can be retried per-chunk without restarting
 * the whole upload.
 *
 * The protocol (mirrors the export-tick pattern):
 *
 *   1. POST /backup/imports                — this controller. Creates
 *      an empty staging file and returns the job_id + chunk_size.
 *   2. POST /backup/imports/{id}/chunk     — append raw bytes at the
 *      offset given via header. Idempotent: same offset on retry.
 *   3. POST /backup/imports/{id}/inspect   — read the header (no
 *      decryption), return is_encrypted + meta. The actual restore
 *      is kicked off by /backup/imports/{id}/start, unchanged.
 */
class UploadImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    /**
     * Server-recommended chunk size returned to the client. 4 MB is
     * comfortably below every common ceiling (PHP's default
     * `post_max_size` is 8 M, nginx's `client_max_body_size` is 1 M
     * but most prod configs raise it to at least 16 M, etc.) and
     * keeps per-chunk RAM cost trivial.
     */
    public const RECOMMENDED_CHUNK_BYTES = 4 * 1024 * 1024;

    /**
     * Hard cap on the claimed total size of an upload. 8 GB is
     * already more than any realistic Flarum backup; refusing
     * larger up front avoids filling the disk by accident.
     */
    public const MAX_TOTAL_BYTES = 8 * 1024 * 1024 * 1024;

    public function __construct(
        protected StoragePaths $paths
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $body = (array) $request->getParsedBody();

        $filename = trim((string) ($body['filename'] ?? ''));
        $size     = (int) ($body['size'] ?? 0);

        if ($filename !== '' && ! str_ends_with(strtolower($filename), '.flarum')) {
            throw new ValidationException(['archive' => 'File must have a .flarum extension.']);
        }
        if ($size <= 0) {
            throw new ValidationException(['archive' => 'Upload size must be greater than zero.']);
        }
        if ($size > self::MAX_TOTAL_BYTES) {
            throw new ValidationException([
                'archive' => sprintf(
                    'Upload exceeds the per-file ceiling (%d bytes > %d).',
                    $size, self::MAX_TOTAL_BYTES
                ),
            ]);
        }

        $jobId = bin2hex(random_bytes(8));
        $dir   = $this->paths->importJobDir($jobId);

        if (! is_writable($dir)) {
            // Deliberately generic: don't echo the absolute staging path
            // back to the client — it only aids filesystem-layout recon.
            // The specific path is available server-side via logs.
            throw new ValidationException([
                'archive' => 'Staging directory is not writable.',
            ]);
        }

        $dest = $dir.DIRECTORY_SEPARATOR.'upload.flarum';

        // Create the empty staging file and persist the expected
        // size so the chunk endpoint can validate offsets and the
        // inspect endpoint can refuse a truncated upload.
        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            throw new ValidationException(['archive' => 'Could not create staging file.']);
        }
        fclose($fh);

        @file_put_contents(
            $dir.DIRECTORY_SEPARATOR.'upload.meta.json',
            json_encode(['expected_size' => $size, 'filename' => $filename])
        );

        return new JsonResponse([
            'job_id'     => $jobId,
            'chunk_size' => self::RECOMMENDED_CHUNK_BYTES,
        ]);
    }
}
