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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\Models\Backup;
use Ramon\Backup\StoragePaths;

/**
 * Envia um backup salvo ao navegador. O caminho passa por
 * `StoragePaths::backupFilePath()`, então um filename adulterado no banco
 * não sai do diretório de backups.
 *
 * O corpo vai direto para a saída do SAPI, seguido de `exit`: middlewares
 * do Flarum fazem `(string) $stream` e carregariam o arquivo inteiro na
 * memória. Aceita `Range` para retomar downloads grandes.
 */
class DownloadBackupController implements RequestHandlerInterface
{
    use AdminOnlyController;

    /** Bytes por ciclo de `fread`/`echo`. */
    private const STREAM_CHUNK_BYTES = 8 * 1024 * 1024;

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

        $this->emitFile($abs, $size, $range, $backup->filename);
    }

    /**
     * Escreve o arquivo (ou a faixa pedida) direto na saída e encerra; nenhum
     * middleware roda depois.
     *
     * @param array{0:int,1:int}|null $range  Faixa [primeiro, último] inclusiva, ou null para o arquivo todo.
     */
    private function emitFile(string $abs, int $size, ?array $range, string $filename): never
    {
        // Buffers abertos pelo Flarum acumulariam o arquivo inteiro na memória.
        while (ob_get_level() > 0) { @ob_end_clean(); }

        // Sem limite de tempo, mas sem ignore_user_abort: aba fechada
        // encerra o script e libera o worker do FPM.
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

        // `filename*` (RFC 5987) leva o nome em UTF-8; `filename` fica em ASCII.
        $safeAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $utf8      = rawurlencode($filename);

        http_response_code($status);
        header('Content-Type: application/octet-stream');
        header('Content-Length: '.$contentLength);
        header('Content-Disposition: attachment; filename="'.$safeAscii.'"; filename*=UTF-8\'\''.$utf8);
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        // Sem isto o nginx bufferiza a resposta inteira em disco antes de
        // repassar; outros servidores ignoram o header.
        header('X-Accel-Buffering: no');
        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        $fh = @fopen($abs, 'rb');
        if ($fh === false) {
            // Headers já enviados: só resta encerrar. Com Accept-Ranges o
            // navegador pode pedir o que faltou.
            exit;
        }

        try {
            if ($start > 0 && fseek($fh, $start) !== 0) {
                exit;
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
