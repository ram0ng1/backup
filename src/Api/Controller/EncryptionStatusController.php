<?php

namespace Ramon\Backup\Api\Controller;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Backup\Crypto\BackupCipher;

/**
 * GET /api/backup/encryption/status
 *
 * Tells the admin UI whether libsodium is available, whether a public
 * key has been generated, and whether the matching private key was
 * pasted into config.php. Refreshed on every panel mount so changes to
 * config.php are picked up live.
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
