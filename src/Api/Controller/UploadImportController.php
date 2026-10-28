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
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports
 *
 * Abre um upload em partes. Corpo: `{ "filename": "...flarum", "size": N }`.
 * Em partes de ~4 MB porque cada camada à frente do PHP tem seu limite
 * (`upload_max_filesize`, `post_max_size`, `client_max_body_size` do nginx)
 * e uma parte que falha pode ser reenviada sozinha.
 *
 *   1. POST /backup/imports              — cria o arquivo vazio, devolve job_id e chunk_size.
 *   2. POST /backup/imports/{id}/chunk   — grava bytes no offset do header; idempotente.
 *   3. POST /backup/imports/{id}/inspect — lê o cabeçalho sem decifrar.
 *   4. POST /backup/imports/{id}/start   — inicia a restauração.
 */
class UploadImportController implements RequestHandlerInterface
{
    use AdminOnlyController;

    /**
     * Tamanho de parte sugerido ao cliente: abaixo do `post_max_size`
     * padrão (8 MB) e barato em memória.
     */
    public const RECOMMENDED_CHUNK_BYTES = 4 * 1024 * 1024;

    /**
     * Teto do tamanho declarado; recusar logo evita encher o disco.
     */
    public const MAX_TOTAL_BYTES = 8 * 1024 * 1024 * 1024;

    public function __construct(
        protected StoragePaths $paths
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertAdmin($request);

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
            // Genérico de propósito: não expor o caminho absoluto ao cliente.
            throw new ValidationException([
                'archive' => 'Staging directory is not writable.',
            ]);
        }

        $dest = $dir.DIRECTORY_SEPARATOR.'upload.flarum';

        // O tamanho esperado fica salvo para o chunk validar offsets e o
        // inspect recusar upload truncado.
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
