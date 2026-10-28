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
use Ramon\Backup\Crypto\BackupCipher;

/**
 * POST /api/backup/encryption/generate-keypair
 *
 * Grava a chave pública nas settings e devolve a privada uma única vez,
 * para o admin colar no config.php. Perdê-la é perder os backups cifrados.
 * Trocar o par não apaga backups antigos: ainda dá para baixá-los e
 * decifrá-los fora daqui com a chave anterior.
 */
class GenerateKeypairController implements RequestHandlerInterface
{
    use AdminOnlyController;

    public function __construct(
        protected BackupCipher $cipher
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertAdmin($request);

        if (! $this->cipher->isAvailable()) {
            throw new ValidationException(['encryption' => 'libsodium is not available on this server.']);
        }

        $body = (array) $request->getParsedBody();
        $hasExisting = $this->cipher->hasPublicKey();
        $acknowledged = ! empty($body['acknowledge_loss']);

        if ($hasExisting && ! $acknowledged) {
            throw new ValidationException([
                'acknowledge_loss' => 'Generating a new keypair will leave existing encrypted backups undecryptable on this server. Confirm to proceed.',
            ]);
        }

        if ($hasExisting) {
            $this->cipher->forgetPublicKey();
        }

        $pair = $this->cipher->generateKeypair();

        return new JsonResponse([
            'public_key'  => $pair['public'],
            'private_key' => $pair['private'],
            'config_key'  => BackupCipher::CONFIG_PRIVATE_KEY,
        ]);
    }
}
