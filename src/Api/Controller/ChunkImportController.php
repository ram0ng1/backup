<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports/{id}/chunk
 *
 * Append a single chunk of the .flarum archive to the staging file.
 *
 * The chunk's byte offset within the final file is given via the
 * `X-Chunk-Offset` request header. The body is the raw chunk payload
 * (`Content-Type: application/octet-stream`). Same offset on retry
 * is idempotent — we seek + overwrite, so a flaky network can resend
 * a chunk without corrupting earlier or later bytes.
 *
 * Response: `{ received, expected }` — total bytes now staged vs the
 * size declared at /backup/imports init time. The frontend uses these
 * for the progress bar and to decide when to call /inspect.
 */
class ChunkImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    /**
     * Per-chunk hard cap. Slightly larger than the recommended chunk
     * size so a slow / mismatched client sending a one-shot bigger
     * chunk doesn't immediately fail; but small enough that one
     * chunk never strains memory_limit during framework parsing.
     */
    private const MAX_CHUNK_BYTES = 16 * 1024 * 1024;

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

        $offsetHeader = trim($request->getHeaderLine('X-Chunk-Offset'));
        if ($offsetHeader === '' || ! preg_match('/^\d+$/', $offsetHeader)) {
            throw new ValidationException([
                'chunk' => 'Missing or invalid X-Chunk-Offset header.',
            ]);
        }
        $offset = (int) $offsetHeader;

        $dir = $this->paths->importJobDir($jobId);
        $meta = $this->loadMeta($dir);
        $dest = $dir.DIRECTORY_SEPARATOR.'upload.flarum';

        if (! is_file($dest)) {
            throw new ValidationException([
                'chunk' => 'Upload session not initialised — call POST /backup/imports first.',
            ]);
        }

        $expected = (int) ($meta['expected_size'] ?? 0);

        // Stream the request body straight to disk in fixed-size
        // reads so we never hold more than 64 KB of the chunk in
        // memory regardless of the chunk's total size.
        $body = $request->getBody();
        $body->rewind();

        if (fseek_or_truncate($dest, $offset) === false) {
            throw new ValidationException([
                'chunk' => 'Failed to position staging file at offset '.$offset.'.',
            ]);
        }

        $fh = @fopen($dest, 'cb'); // c = open for read+write, don't truncate, create if missing
        if ($fh === false) {
            throw new ValidationException(['chunk' => 'Could not open staging file.']);
        }

        try {
            if (fseek($fh, $offset) !== 0) {
                throw new ValidationException([
                    'chunk' => 'Failed to seek to offset '.$offset.' in staging file.',
                ]);
            }

            $written = 0;
            while (! $body->eof()) {
                $piece = $body->read(64 * 1024);
                if ($piece === '') break;

                $written += strlen($piece);
                if ($written > self::MAX_CHUNK_BYTES) {
                    throw new ValidationException([
                        'chunk' => sprintf(
                            'Chunk exceeds per-request limit (%d bytes).',
                            self::MAX_CHUNK_BYTES
                        ),
                    ]);
                }
                if ($expected > 0 && $offset + $written > $expected) {
                    throw new ValidationException([
                        'chunk' => 'Chunk would write past the declared upload size.',
                    ]);
                }

                $bytes = fwrite($fh, $piece);
                if ($bytes === false || $bytes !== strlen($piece)) {
                    throw new ValidationException([
                        'chunk' => 'Write failed at offset '.($offset + $written - strlen($piece)).' — disk full?',
                    ]);
                }
            }
        } finally {
            fclose($fh);
        }

        $received = filesize($dest) ?: 0;
        return new JsonResponse([
            'received' => $received,
            'expected' => $expected,
        ]);
    }

    /** @return array{expected_size?: int, filename?: string} */
    private function loadMeta(string $dir): array
    {
        $path = $dir.DIRECTORY_SEPARATOR.'upload.meta.json';
        if (! is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

/**
 * Pre-create / pre-extend the staging file to at least `$offset`
 * bytes. Without this, fseek on an `cb` mode handle won't grow the
 * file, and writes can land at the wrong offset.
 */
function fseek_or_truncate(string $path, int $offset): int|false
{
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size === false) return false;
    if ($size >= $offset) return $size;

    // Extend with zero bytes up to the offset. We rarely take this
    // path — only when chunks arrive out of order, which the client
    // doesn't normally do but is allowed by the protocol.
    $fh = @fopen($path, 'ab');
    if ($fh === false) return false;
    try {
        $pad = $offset - $size;
        $chunk = str_repeat("\0", min(64 * 1024, $pad));
        while ($pad > 0) {
            $w = fwrite($fh, substr($chunk, 0, min(strlen($chunk), $pad)));
            if ($w === false || $w === 0) return false;
            $pad -= $w;
        }
    } finally {
        fclose($fh);
    }
    return $offset;
}
