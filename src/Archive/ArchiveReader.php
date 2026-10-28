<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Archive;

use Ramon\Backup\Crypto\BackupCipher;
use RuntimeException;

/**
 * Leitor em streaming de arquivos `.flarum`, par do `ArchiveWriter`.
 *
 * Entrega uma entrada por vez para o import trabalhar em ticks sem
 * carregar o arquivo inteiro na memória. Arquivo cifrado exige
 * `prepareEncrypted()` depois de `openHeader()`. O estado do secretstream
 * é serializável, então cada tick retoma do ponto salvo em vez de
 * decifrar tudo de novo desde o início.
 */
class ArchiveReader
{
    /** @var resource */
    private $fh;

    /** @var array<string, mixed> */
    private array $meta = [];

    private bool $encrypted;

    /** Offset logo depois do cabeçalho (início do corpo). */
    private int $bodyStart = 0;

    /** Posição lógica no stream de entradas já decifrado. */
    private int $entryStreamPos = 0;

    /** Estado do secretstream (pull); só em arquivos cifrados. */
    private ?string $streamState = null;

    /** Texto claro já decifrado e ainda não consumido. */
    private string $plainBuffer = '';

    /** O pull já viu a tag FINAL. */
    private bool $streamExhausted = false;

    /**
     * @param resource $fh
     * @param array<string, mixed> $meta
     */
    private function __construct($fh, bool $encrypted, array $meta, int $bodyStart)
    {
        $this->fh = $fh;
        $this->encrypted = $encrypted;
        $this->meta = $meta;
        $this->bodyStart = $bodyStart;
    }

    /**
     * Lê só o cabeçalho, sem decifrar nada. Em arquivo cifrado, chame
     * `prepareEncrypted()` em seguida.
     */
    public static function openHeader(string $path): self
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Could not open archive: '.$path);
        }

        $magic = fread($fh, strlen(Format::MAGIC));
        if ($magic !== Format::MAGIC) {
            fclose($fh);
            throw new RuntimeException('Not a Flarum backup archive (bad magic).');
        }

        $flagsByte = fread($fh, 1);
        if ($flagsByte === false || strlen($flagsByte) !== 1) {
            fclose($fh);
            throw new RuntimeException('Truncated archive header.');
        }
        $flags = ord($flagsByte);
        $encrypted = ($flags & Format::FLAG_ENCRYPTED) !== 0;

        $metaLenRaw = fread($fh, 4);
        if ($metaLenRaw === false || strlen($metaLenRaw) !== 4) {
            fclose($fh);
            throw new RuntimeException('Truncated archive header.');
        }
        $metaLen = unpack('N', $metaLenRaw)[1];
        if ($metaLen > Format::MAX_META_BYTES) {
            fclose($fh);
            throw new RuntimeException('Archive metadata length is implausible.');
        }

        $metaJson = $metaLen > 0 ? fread($fh, $metaLen) : '';
        if ($metaJson === false || strlen($metaJson) !== $metaLen) {
            fclose($fh);
            throw new RuntimeException('Truncated archive metadata.');
        }
        $meta = json_decode($metaJson, true) ?: [];

        $bodyStart = ftell($fh);
        return new self($fh, $encrypted, $meta, $bodyStart);
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * Desembrulha a chave e consome o cabeçalho do secretstream. Sem
     * `$privateKeyB64`, usa a chave do config.php.
     */
    public function prepareEncrypted(BackupCipher $cipher, ?string $privateKeyB64): void
    {
        if (! $this->encrypted) return;

        $wrapped = fread($this->fh, BackupCipher::WRAPPED_KEY_BYTES);
        if ($wrapped === false || strlen($wrapped) !== BackupCipher::WRAPPED_KEY_BYTES) {
            throw new RuntimeException('Truncated wrapped-key block.');
        }

        $symmetricKey = $cipher->unwrapSymmetricKey($wrapped, $privateKeyB64);

        $streamHeader = fread($this->fh, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($streamHeader === false || strlen($streamHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Truncated secretstream header.');
        }

        $this->streamState = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $symmetricKey);
        sodium_memzero($symmetricKey);
    }

    /**
     * Cabeçalho da próxima entrada, ou null no terminador de nome vazio.
     *
     * @return array{name: string, type: int, length: int}|null
     */
    public function nextEntry(): ?array
    {
        $nameLenRaw = $this->readPlain(4);
        $nameLen = unpack('N', $nameLenRaw)[1];
        if ($nameLen === 0) {
            return null;
        }
        if ($nameLen > 4096) {
            throw new RuntimeException('Entry name length is implausible.');
        }
        $name = $this->readPlain($nameLen);
        $typeByte = $this->readPlain(1);
        $lenRaw = $this->readPlain(8);
        $length = unpack('J', $lenRaw)[1];
        // Acima de 2^63 o `J` volta negativo e o leitor pularia a entrada.
        if ($length < 0) {
            throw new RuntimeException('Entry length is implausible.');
        }

        return [
            'name'   => $name,
            'type'   => ord($typeByte),
            'length' => $length,
        ];
    }

    /**
     * O chamador precisa ler exatamente `length` bytes da entrada antes de
     * chamar `nextEntry()` de novo: não há como pular o resto.
     */
    public function readEntryBytes(int $bytes): string
    {
        if ($bytes <= 0) return '';
        return $this->readPlain($bytes);
    }

    public function close(): void
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /**
     * Estado para o próximo tick continuar daqui sem reler (e redecifrar)
     * as entradas anteriores. `stream_state` é null em arquivo sem cifra.
     *
     * @return array{fpos: int, entry_stream_pos: int, stream_state: ?string, plain_buffer: string, stream_exhausted: bool}
     */
    public function serializeState(): array
    {
        return [
            'fpos'             => (int) ftell($this->fh),
            'entry_stream_pos' => $this->entryStreamPos,
            'stream_state'     => $this->streamState,
            'plain_buffer'     => $this->plainBuffer,
            'stream_exhausted' => $this->streamExhausted,
        ];
    }

    /**
     * Retoma de um `serializeState()`. Chame logo após `openHeader()`, NO
     * LUGAR de `prepareEncrypted()`: o estado salvo já carrega a chave
     * simétrica, então a chave privada não é consultada.
     *
     * @param array{fpos?: int|null, entry_stream_pos?: int, stream_state?: ?string, plain_buffer?: string, stream_exhausted?: bool} $state
     */
    public function resumeState(array $state): void
    {
        if ($this->encrypted) {
            $ss = $state['stream_state'] ?? null;
            if (! is_string($ss) || $ss === '') {
                throw new RuntimeException('Encrypted archive resume is missing the secretstream state.');
            }
            $this->streamState     = $ss;
            $this->plainBuffer     = (string) ($state['plain_buffer'] ?? '');
            $this->streamExhausted = (bool) ($state['stream_exhausted'] ?? false);
        }

        $this->entryStreamPos = (int) ($state['entry_stream_pos'] ?? 0);

        $fpos = (int) ($state['fpos'] ?? $this->bodyStart);
        if (fseek($this->fh, $fpos) !== 0) {
            throw new RuntimeException('Could not seek archive on resume.');
        }
    }

    /** Decifra mais chunks do secretstream conforme a leitura pede. */
    private function readPlain(int $bytes): string
    {
        if (! $this->encrypted) {
            $data = fread($this->fh, $bytes);
            if ($data === false || strlen($data) !== $bytes) {
                throw new RuntimeException('Truncated archive body.');
            }
            $this->entryStreamPos += $bytes;
            return $data;
        }

        while (strlen($this->plainBuffer) < $bytes) {
            if ($this->streamExhausted) {
                throw new RuntimeException('Archive stream ended before requested bytes.');
            }
            $this->pullChunk();
        }

        $out = substr($this->plainBuffer, 0, $bytes);
        $this->plainBuffer = substr($this->plainBuffer, $bytes);
        $this->entryStreamPos += $bytes;
        return $out;
    }

    private function pullChunk(): void
    {
        $lenRaw = fread($this->fh, 4);
        if ($lenRaw === false || strlen($lenRaw) !== 4) {
            throw new RuntimeException('Truncated encrypted chunk length.');
        }
        $len = unpack('N', $lenRaw)[1];
        if ($len <= 0 || $len > Format::CHUNK_SIZE + 1024) {
            throw new RuntimeException('Encrypted chunk length is implausible.');
        }
        $cipher = fread($this->fh, $len);
        if ($cipher === false || strlen($cipher) !== $len) {
            throw new RuntimeException('Truncated encrypted chunk.');
        }

        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($this->streamState, $cipher);
        if ($result === false) {
            throw new RuntimeException('Could not decrypt chunk (key mismatch or corrupt archive).');
        }
        [$plain, $tag] = $result;
        $this->plainBuffer .= $plain;

        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $this->streamExhausted = true;
        }
    }
}
