<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Crypto;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * Criptografia híbrida dos backups. A chave pública fica nos settings; a
 * privada só no config.php (`backup-private-key`), colada à mão, para o
 * processo web não ter como vazar uma chave que nunca recebeu.
 *
 * Cada backup usa uma chave simétrica nova K: o corpo vai em secretstream
 * (XChaCha20-Poly1305), cujo estado é serializável entre ticks, e K vai
 * selada com a chave pública (`sodium_crypto_box_seal`, 80 bytes). No
 * import em outro fórum, a chave privada é colada no modal; o config.php
 * do destino não é tocado.
 */
class BackupCipher
{
    public const SETTING_PUBLIC_KEY = 'ramon-backup.encryption_public_key';
    public const CONFIG_PRIVATE_KEY = 'backup-private-key';

    /** sealed_box(public, K) com K de 32 bytes: 32 + 48 = 80. */
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
     * Confere se a pública derivada da privada bate com a salva. Privada de
     * outro par no config.php parece funcionar, mas nenhum decrypt passaria.
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

    public function generateSymmetricKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    /** Embrulha com a chave pública configurada (sealed_box, 80 bytes). */
    public function wrapSymmetricKey(string $symmetricKey): string
    {
        $public = $this->loadPublicKey();
        if ($public === null) {
            throw new RuntimeException('No public key configured.');
        }
        return sodium_crypto_box_seal($symmetricKey, $public);
    }

    /**
     * Embrulha com outra chave pública, para preparar um arquivo que vai ser
     * restaurado em outro fórum.
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
     * Usa a chave privada do config.php, ou a passada em base64 (caso do
     * import, quando a chave vem junto com o arquivo).
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
     * Gera um par novo e salva a pública. A privada só é devolvida aqui, uma
     * vez, para o admin colar no config.php.
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
