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
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Locale\Messages;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports/{id}/chunk
 *
 * Grava uma parte do arquivo no offset do header `X-Chunk-Offset`; o corpo
 * são os bytes crus. Reenviar o mesmo offset sobrescreve, então repetir é
 * seguro. Responde `{ received, expected }` para a barra de progresso.
 */
class ChunkImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    /**
     * Teto por requisição: folga sobre o tamanho recomendado, mas pequeno o
     * bastante para não pesar no memory_limit.
     */
    private const MAX_CHUNK_BYTES = 16 * 1024 * 1024;

    public function __construct(
        protected StoragePaths $paths,
        protected Messages $messages
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertAdmin($request);

        $jobId = (string) ($request->getQueryParams()['id'] ?? '');
        if (! preg_match('/^[a-f0-9]{16}$/', $jobId)) {
            throw new ValidationException(['id' => $this->messages->get('errors.invalid_job')]);
        }

        $offsetHeader = trim($request->getHeaderLine('X-Chunk-Offset'));
        if ($offsetHeader === '' || ! preg_match('/^\d+$/', $offsetHeader)) {
            throw new ValidationException([
                'chunk' => $this->messages->get('errors.bad_offset_header'),
            ]);
        }
        $offset = (int) $offsetHeader;

        $dir = $this->paths->importJobDir($jobId);
        $meta = $this->loadMeta($dir);
        $dest = $dir.DIRECTORY_SEPARATOR.'upload.flarum';

        if (! is_file($dest)) {
            throw new ValidationException([
                'chunk' => $this->messages->get('errors.upload_not_initialised'),
            ]);
        }

        $expected = (int) ($meta['expected_size'] ?? 0);
        // Sem tamanho declarado não há limite; e o preenchimento com zeros
        // abaixo grava bytes reais até o offset, antes de ler o corpo.
        if ($expected <= 0 || $offset >= $expected) {
            throw new ValidationException([
                'chunk' => $this->messages->get('errors.offset_out_of_range'),
            ]);
        }

        // Lê em blocos de 64 KB para não segurar a parte inteira na memória.
        $body = $request->getBody();
        $body->rewind();

        if ($this->ensureFileSize($dest, $offset) === false) {
            throw new ValidationException([
                'chunk' => $this->messages->get('errors.seek_failed', ['offset' => $offset]),
            ]);
        }

        $fh = @fopen($dest, 'cb'); // 'c' não trunca o arquivo
        if ($fh === false) {
            throw new ValidationException(['chunk' => $this->messages->get('errors.open_staging_failed')]);
        }

        try {
            if (fseek($fh, $offset) !== 0) {
                throw new ValidationException([
                    'chunk' => $this->messages->get('errors.seek_failed', ['offset' => $offset]),
                ]);
            }

            $written = 0;
            while (! $body->eof()) {
                $piece = $body->read(64 * 1024);
                if ($piece === '') break;

                $written += strlen($piece);
                if ($written > self::MAX_CHUNK_BYTES) {
                    throw new ValidationException([
                        'chunk' => $this->messages->get('errors.chunk_too_large', ['max' => self::MAX_CHUNK_BYTES]),
                    ]);
                }
                if ($offset + $written > $expected) {
                    throw new ValidationException([
                        'chunk' => $this->messages->get('errors.chunk_past_end'),
                    ]);
                }

                $bytes = fwrite($fh, $piece);
                if ($bytes === false || $bytes !== strlen($piece)) {
                    throw new ValidationException([
                        'chunk' => $this->messages->get('errors.write_failed', ['offset' => $offset + $written - strlen($piece)]),
                    ]);
                }
            }
        } finally {
            fclose($fh);
        }

        clearstatcache(true, $dest);
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
        $raw = @file_get_contents($path); /* leitura de arquivo local, sem URL de input; nosemgrep: flarum-v2-server-side-fetch */
        if ($raw === false) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Garante o arquivo com pelo menos `$offset` bytes; fseek em modo `cb`
     * não estende o arquivo e a escrita cairia no lugar errado.
     */
    private function ensureFileSize(string $path, int $offset): int|false
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false) return false;
        if ($size >= $offset) return $size;

        // Só acontece com partes fora de ordem, que o protocolo permite.
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
}
