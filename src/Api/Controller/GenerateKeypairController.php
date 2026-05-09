<?php

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
 * Same UX shape as the verified extension's generator: the new public
 * key is persisted in settings, the private half is returned ONCE so
 * the admin can paste it into config.php manually. Subsequent calls
 * never re-emit the private key — losing it means losing the ability
 * to decrypt previously-created backups.
 *
 * Backups are NOT erased on rotation. Encryption here protects the
 * archive contents, but the archive files themselves are still useful
 * to download and decrypt out-of-band, even after a key change. The
 * admin is told this clearly in the modal.
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
        $this->assertCanManage($request);

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
