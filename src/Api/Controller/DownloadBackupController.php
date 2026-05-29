<?php

namespace Ramon\Backup\Api\Controller;

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;

/**
 * Streams a saved backup file to the browser.
 *
 * Path resolution goes through `StoragePaths::backupFilePath()`,
 * which whitelists the filename pattern and verifies the resolved
 * absolute path lives under the canonical backups directory — so a
 * malicious id that resolves to a row with a tampered filename can't
 * escape.
 *
 * Why we bypass PSR-7 emission for the body:
 *
 *   Wrapping the file in a `Stream` and returning a PSR-7 `Response`
 *   *should* let the framework's SAPI emitter pump bytes through in
 *   chunks. In practice, several middlewares in the Flarum stack
 *   (request/response logging, CORS, CSP) do `(string) $stream`
 *   somewhere along the chain — which materialises the whole body in
 *   memory. With a 1+ GB `.flarum` archive that promptly hits
 *   `memory_limit`, PHP-FPM dies with a 502, and the browser ends up
 *   on `chrome-error://chromewebdata/` (the symptom this fix targets).
 *
 *   So we send headers + body straight to the SAPI output and call
 *   `exit` — no middleware ever gets to read the body. Plain HTTP/1.1
 *   `Range` is honoured so a dropped connection on a multi-GB
 *   download can resume from the last byte transferred.
 */
class DownloadBackupController implements RequestHandlerInterface
{
    use AdminOnlyController;

    /** Bytes per `fread`/`echo` cycle. 8 MB balances syscalls vs RAM. */
    private const STREAM_CHUNK_BYTES = 8 * 1024 * 1024;

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
        if ($abs === null || ! is_file($abs)) {
            // Deliberately generic: don't echo the absolute path or the
            // (DB-stored, potentially tampered) filename back to the
            // client — that only aids filesystem-layout recon. The
            // specifics are available server-side via logs if needed.
            throw new ValidationException([
                'file' => 'Backup file is not available.',
            ]);
        }

        $size  = filesize($abs) ?: 0;
        $range = $this->parseRange($request->getHeaderLine('Range'), $size);

        $this->emitFile($abs, $size, $range, $backup->filename);

        // Unreachable in practice (emitFile() exits). Returning a
        // bare empty response keeps the type contract honest in
        // case a test harness ever calls this without the SAPI.
        return new EmptyResponse(200);
    }

    /**
     * Stream the (possibly partial) file body straight to the SAPI
     * output and `exit`. After this call, no further middleware runs
     * and PHP-FPM doesn't try to materialise the response body.
     *
     * @param array{0:int,1:int}|null $range  Byte range [first, last] inclusive, or null for whole file.
     */
    private function emitFile(string $abs, int $size, ?array $range, string $filename): never
    {
        // Drain any output buffers Flarum / middlewares started.
        // Without this, every fread/echo gets buffered (defeats the
        // chunked-emit purpose) and may also crash on memory_limit.
        while (ob_get_level() > 0) { @ob_end_clean(); }

        // Long downloads may run for many minutes on slow links;
        // ignore_user_abort is intentionally OFF so a closed tab
        // stops the script immediately and frees the FPM worker.
        @set_time_limit(0);

        if ($range === null) {
            $start = 0;
            $end   = max(0, $size - 1);
            $status = 200;
            $contentLength = $size;
        } else {
            [$start, $end] = $range;
            $status = 206;
            $contentLength = $end - $start + 1;
        }

        // Headers must precede the first byte of body — at this point
        // ob is closed, so each header() goes out as soon as we send
        // the first byte. Quote the filename to survive spaces / non-
        // ASCII (RFC 5987 'filename*' covers UTF-8 properly).
        $safeAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $utf8      = rawurlencode($filename);

        http_response_code($status);
        header('Content-Type: application/octet-stream');
        header('Content-Length: '.$contentLength);
        header('Content-Disposition: attachment; filename="'.$safeAscii.'"; filename*=UTF-8\'\''.$utf8);
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        // nginx (the most common reverse proxy in front of Flarum)
        // buffers proxied responses to disk by default. With a multi-
        // GB body that means a long pause at the start, and an even
        // longer one at the end before bytes reach the browser. This
        // header asks nginx to passthrough chunks as PHP emits them.
        // Apache + most other servers ignore the header harmlessly.
        header('X-Accel-Buffering: no');
        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        $fh = @fopen($abs, 'rb');
        if ($fh === false) {
            // Headers already flushed — best we can do is end the
            // connection cleanly. The browser will treat it as a
            // truncated download and (because we set Accept-Ranges)
            // can retry the missing range.
            exit;
        }

        try {
            if ($start > 0 && fseek($fh, $start) !== 0) {
                exit; // could not seek — give up cleanly
            }

            $remaining = $contentLength;
            while ($remaining > 0 && ! feof($fh)) {
                if (connection_aborted()) break;

                $want  = (int) min(self::STREAM_CHUNK_BYTES, $remaining);
                $chunk = fread($fh, $want);
                if ($chunk === false || $chunk === '') break;

                echo $chunk;
                @flush();

                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($fh);
        }

        exit;
    }

    /**
     * Parse a single-range `Range: bytes=START-END` header. Returns
     * `[start, end]` (inclusive) or null when the header is absent /
     * malformed / unsatisfiable. Multi-range requests are intentionally
     * not supported — they require multipart/byteranges responses,
     * which add complexity for marginal benefit on a single big
     * archive download.
     *
     * @return array{0:int,1:int}|null
     */
    private function parseRange(string $header, int $size): ?array
    {
        if ($header === '' || $size <= 0) return null;
        if (! preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $m)) return null;

        $startStr = $m[1];
        $endStr   = $m[2];

        if ($startStr === '' && $endStr === '') return null;

        if ($startStr === '') {
            // Suffix range "-N" = last N bytes.
            $suffix = (int) $endStr;
            if ($suffix <= 0) return null;
            $start = max(0, $size - $suffix);
            $end   = $size - 1;
        } else {
            $start = (int) $startStr;
            $end   = $endStr === '' ? $size - 1 : (int) $endStr;
        }

        if ($start > $end || $start >= $size) return null;
        if ($end >= $size) $end = $size - 1;

        return [$start, $end];
    }
}
