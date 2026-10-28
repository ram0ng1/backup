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
use Ramon\Backup\Archive\ArchiveMeta;
use Ramon\Backup\Archive\ArchiveReader;
use Ramon\Backup\Environment\StackSnapshot;
use Ramon\Backup\StoragePaths;

/**
 * POST /api/backup/imports/{id}/inspect
 *
 * Fecha o upload: lê o cabeçalho sem decifrar e diz se o arquivo está
 * cifrado. Recusa upload menor que o tamanho declarado.
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
        $this->assertAdmin($request);

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

        // Barra aqui o stack incompatível, antes da confirmação; o ImportJob
        // checa de novo para CLI e chamadas diretas. O upload fica para não
        // precisar reenviar após atualizar o PHP; o prune limpa depois.
        $blocking = StackSnapshot::blockingReason($archiveMeta);
        if ($blocking !== null) {
            throw new ValidationException(['archive' => $blocking]);
        }

        return new JsonResponse([
            'job_id'       => $jobId,
            'is_encrypted' => $isEncrypted,
            'meta'         => ArchiveMeta::forDisplay($archiveMeta),
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
