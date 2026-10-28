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

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Crypto\BackupCipher;

/**
 * GET /api/backup/encryption/status
 *
 * Diz ao painel se há libsodium, chave pública gerada e chave privada no
 * config.php. Consultado a cada abertura do painel.
 */
class EncryptionStatusController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected BackupCipher $cipher
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertCanManage($request);
        return new JsonResponse($this->cipher->status());
    }
}
