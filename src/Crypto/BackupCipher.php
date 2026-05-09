<?php

namespace Ramon\Backup\Crypto;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * Asymmetric encryption for backups.
 *
 * Same trust model as the verified extension: the admin generates a
 * keypair, the public half lives in the settings table (cheap to load,
 * non-secret), and the private half MUST be pasted into config.php under
 * `backup-private-key` BY HAND. The web process can't be coerced into
 * leaking a key it has never been told.
 *
 * Hybrid encryption:
 *   - For each backup we generate a fresh 32-byte symmetric key K.
 *   - The body is encrypted with libsodium secretstream
 *     (XChaCha20-Poly1305) keyed by K — chunked, authenticated, and
 *     resumable across HTTP ticks because the secretstream state is a
 *     plain binary blob we can persist between requests.
 *   - K itself is sealed with the admin's public key
 *     (`sodium_crypto_box_seal`), producing a fixed-size 80-byte header
 *     that only the matching private key can unwrap.
 *
 * On import into a foreign Flarum install, the operator pastes the
 * private key into the import modal — we never touch the import target's
 * config.php during a restore.
 */
class BackupCipher
{
    public const SETTING_PUBLIC_KEY = 'ramon-backup.encryption_public_key';
    public const CONFIG_PRIVATE_KEY = 'backup-private-key';

    /** sealed_box(public, K) where K is 32 bytes → 32 + 48 = 80. */
    public const WRAPPED_KEY_BYTES = 80;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config
    ) {
    }

    public function isAvailable(): bool
    {
        return function_exists('sodium_crypto_box_seal')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push');
    }

    public function hasPublicKey(): bool
    {
        return $this->loadPublicKey() !== null;
    }

    public function hasPrivateKey(): bool
    {
        return $this->loadPrivateKey() !== null;
    }

    /**
     * Both keys present AND the public key derived from the private key
     * matches the stored public key. A mismatch means the admin pasted a
     * private key from a different keypair into config.php — encryption
     * looks healthy but every decrypt would fail.
     */
    public function keysMatch(): ?bool
    {
        $public = $this->loadPublicKey();
        $secret = $this->loadPrivateKey();
        if ($public === null || $secret === null) {
            return null;
        }

        $derived = sodium_crypto_box_publickey_from_secretkey($secret);
        $match = hash_equals($public, $derived);

        sodium_memzero($secret);

        return $match;
    }

    public function canEncrypt(): bool
    {
        return $this->isAvailable() && $this->hasPublicKey();
    }

    public function canDecrypt(): bool
    {
        return $this->isAvailable() && $this->keysMatch() === true;
    }

    /**
     * Generate a fresh 32-byte symmetric key. The caller must seal it
     * with the public key (or a foreign one passed at import time) and
     * embed the wrapped result in the archive header.
     */
    public function generateSymmetricKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    /**
     * Wrap a symmetric key with the configured public key, returning the
     * 80-byte sealed_box ciphertext.
     */
    public function wrapSymmetricKey(string $symmetricKey): string
    {
        $public = $this->loadPublicKey();
        if ($public === null) {
            throw new RuntimeException('No public key configured.');
        }
        return sodium_crypto_box_seal($symmetricKey, $public);
    }

    /**
     * Wrap a symmetric key with an arbitrary base64 public key. Used at
     * export time when the admin chooses to encrypt to a key OTHER than
     * the one stored in this install — for instance, when preparing an
     * archive for transfer to a different Flarum.
     */
    public function wrapSymmetricKeyWith(string $symmetricKey, string $base64PublicKey): string
    {
        $public = $this->decodePublicKey($base64PublicKey);
        if ($public === null) {
            throw new RuntimeException('Provided public key is invalid.');
        }
        return sodium_crypto_box_seal($symmetricKey, $public);
    }

    /**
     * Unwrap a sealed symmetric key using either the configured private
     * key (default) or one provided by the caller as base64. The latter
     * is what the import flow uses when the keypair travels with the
     * file rather than living in config.php.
     */
    public function unwrapSymmetricKey(string $wrapped, ?string $base64PrivateKey = null): string
    {
        if (strlen($wrapped) !== self::WRAPPED_KEY_BYTES) {
            throw new RuntimeException('Wrapped-key block has unexpected size.');
        }

        if ($base64PrivateKey !== null && $base64PrivateKey !== '') {
            $secret = $this->decodePrivateKey($base64PrivateKey);
            if ($secret === null) {
                throw new RuntimeException('Provided private key is invalid.');
            }
            $public = sodium_crypto_box_publickey_from_secretkey($secret);
        } else {
            $secret = $this->loadPrivateKey();
            $public = $this->loadPublicKey();
            if ($secret === null || $public === null) {
                throw new RuntimeException('Private or public key not available.');
            }
        }

        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $public);
        $key = sodium_crypto_box_seal_open($wrapped, $keypair);
        sodium_memzero($keypair);
        sodium_memzero($secret);

        if ($key === false) {
            throw new RuntimeException('Could not unwrap the symmetric key — wrong keypair or corrupt archive.');
        }

        return $key;
    }

    /**
     * Generate a fresh keypair, store the public half in settings, and
     * return both as base64. The private half is shown ONCE to the admin
     * so they can paste it into config.php; subsequent calls do not
     * re-emit it.
     *
     * @return array{public: string, private: string}
     */
    public function generateKeypair(): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('libsodium is not available on this server.');
        }

        $keypair = sodium_crypto_box_keypair();
        $public  = sodium_crypto_box_publickey($keypair);
        $secret  = sodium_crypto_box_secretkey($keypair);

        $publicB64  = base64_encode($public);
        $privateB64 = base64_encode($secret);

        $this->settings->set(self::SETTING_PUBLIC_KEY, $publicB64);

        sodium_memzero($keypair);
        sodium_memzero($secret);

        return ['public' => $publicB64, 'private' => $privateB64];
    }

    public function forgetPublicKey(): void
    {
        $this->settings->set(self::SETTING_PUBLIC_KEY, '');
    }

    public function status(): array
    {
        $available  = $this->isAvailable();
        $hasPublic  = $this->hasPublicKey();
        $hasPrivate = $this->hasPrivateKey();
        $match      = ($hasPublic && $hasPrivate) ? $this->keysMatch() : null;

        return [
            'available'             => $available,
            'has_public_key'        => $hasPublic,
            'private_key_present'   => $hasPrivate,
            'keys_match'            => $match,
            'healthy'               => $available && $hasPublic && $hasPrivate && $match === true,
            'requires_regeneration' => $available && $hasPublic && (! $hasPrivate || $match === false),
            'public_key'            => $hasPublic ? (string) $this->settings->get(self::SETTING_PUBLIC_KEY, '') : null,
            'config_key'            => self::CONFIG_PRIVATE_KEY,
        ];
    }

    public function loadPublicKey(): ?string
    {
        $raw = $this->settings->get(self::SETTING_PUBLIC_KEY, '');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        return $this->decodePublicKey($raw);
    }

    private function loadPrivateKey(): ?string
    {
        $raw = $this->config[self::CONFIG_PRIVATE_KEY] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        return $this->decodePrivateKey($raw);
    }

    private function decodePublicKey(string $b64): ?string
    {
        $decoded = base64_decode(trim($b64), true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return null;
        }
        return $decoded;
    }

    private function decodePrivateKey(string $b64): ?string
    {
        $decoded = base64_decode(trim($b64), true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            return null;
        }
        return $decoded;
    }
}
