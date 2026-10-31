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

use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Http\FileRangeStream;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;

/**
 * Envia um backup salvo ao navegador. O caminho passa por
 * `StoragePaths::backupFilePath()`, então um filename adulterado no banco
 * não sai do diretório de backups.
 *
 * O corpo é um `FileRangeStream`, que o Emitter do Flarum transmite em
 * pedaços sem carregar o arquivo na memória; a resposta volta pela pilha de
 * middlewares como qualquer outra. Aceita `Range` para retomar downloads
 * grandes.
 */
class DownloadBackupController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected StoragePaths $paths,
        protected Messages $messages
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);

        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $backup = Backup::query()->find($id);

        if (! $backup) {
            throw new ValidationException(['id' => $this->messages->get('errors.backup_not_found')]);
        }

        $abs = $this->paths->backupFilePath($backup->filename);
        if ($abs === null || ! is_file($abs)) {
            // Genérico de propósito: não expor caminho nem filename ao cliente.
            throw new ValidationException([
                'file' => $this->messages->get('errors.file_unavailable'),
            ]);
        }

        $size  = filesize($abs) ?: 0;
        $range = $this->parseRange($request->getHeaderLine('Range'), $size);

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $end - $start + 1;

        // `filename*` (RFC 5987) leva o nome em UTF-8; `filename` fica em ASCII.
        $safeAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', $backup->filename);
        $utf8      = rawurlencode($backup->filename);

        $response = new Response(
            new FileRangeStream($abs, $start, $length),
            $range === null ? 200 : 206,
            [
                'Content-Type'           => 'application/octet-stream',
                'Content-Length'         => (string) $length,
                'Content-Disposition'    => 'attachment; filename="'.$safeAscii.'"; filename*=UTF-8\'\''.$utf8,
                'Accept-Ranges'          => 'bytes',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'no-store',
                // Sem isto o nginx bufferiza a resposta inteira em disco antes
                // de repassar; outros servidores ignoram o header.
                'X-Accel-Buffering'      => 'no',
            ]
        );

        return $range === null
            ? $response
            : $response->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size));
    }

    /**
     * Lê `Range: bytes=INÍCIO-FIM` de faixa única; null se ausente, inválido
     * ou impossível. Múltiplas faixas (multipart/byteranges) não são aceitas.
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
            // "-N" são os últimos N bytes.
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
