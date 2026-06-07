<?php

namespace Ramon\Backup\Archive;

use Ramon\Backup\Crypto\BackupCipher;
use RuntimeException;

/**
 * Streaming reader for `.flarum` archives.
 *
 * Symmetric counterpart to `ArchiveWriter`. Designed to expose entries
 * one at a time so the importer can stage them through a chunked-tick
 * progress flow without ever loading the whole archive into memory.
 *
 * Lifecycle:
 *   - openHeader($path)        — read magic + meta JSON. Tells you whether
 *                                the archive is encrypted (and you need
 *                                to provide a private key) without
 *                                committing to any further parsing.
 *   - prepare($pathOrKey)      — second step, only if encrypted:
 *                                unwrap the symmetric key and consume
 *                                the secretstream init header. After
 *                                this, the reader is positioned at the
 *                                first encrypted chunk.
 *   - nextEntry()              — yields {name, type, length, stream}.
 *                                The `stream` is a callable readNBytes()
 *                                so the importer can pull payload bytes
 *                                in its own chunks without seeking.
 *
 * Resumability is shallower than the writer's: import ticks open the
 * file, fast-forward to the entry+offset they were processing, and
 * continue. We persist `(offset_in_archive, entry_remaining)` between
 * ticks; on resume we re-decrypt from the last secretstream chunk
 * boundary we recorded. Decryption state IS serializable just like on
 * the write side.
 */
class ArchiveReader
{
    /** @var resource */
    private $fh;

    /** @var array<string, mixed> */
    private array $meta = [];

    private bool $encrypted;

    /** Offset in the file just past the file header (== start of body). */
    private int $bodyStart = 0;

    /** Current logical offset within the decrypted entry stream. */
    private int $entryStreamPos = 0;

    /** libsodium secretstream pull state — only set for encrypted archives. */
    private ?string $streamState = null;

    /** Plaintext bytes already pulled from secretstream but not yet consumed. */
    private string $plainBuffer = '';

    /** Has the secretstream pull seen a FINAL tag? */
    private bool $streamExhausted = false;

    private function __construct($fh, bool $encrypted, array $meta, int $bodyStart)
    {
        $this->fh = $fh;
        $this->encrypted = $encrypted;
        $this->meta = $meta;
        $this->bodyStart = $bodyStart;
    }

    /**
     * Read the header without committing to decryption. Returns a
     * partially-initialised reader; for encrypted archives you must call
     * `prepareEncrypted($privateKeyB64)` next.
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
     * Consume the wrapped key + secretstream init header, leaving the
     * reader positioned at the first encrypted chunk. `$privateKeyB64`
     * is optional — when null we use the configured config.php key.
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
     * Pull the next entry header from the entry stream. Returns null
     * when the zero-length terminator is encountered.
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

        return [
            'name'   => $name,
            'type'   => ord($typeByte),
            'length' => $length,
        ];
    }

    /**
     * Read up to $bytes of the current entry's payload. The caller is
     * expected to have read exactly `length` total bytes since the last
     * `nextEntry()` call before invoking it again.
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
     * Snapshot enough state to resume the next tick exactly where this
     * one stopped — without re-reading (and, for encrypted archives,
     * re-decrypting) every earlier entry from the start. `stream_state`
     * is null for plaintext archives.
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
     * Resume a reader from a prior tick's `serializeState()` snapshot.
     * Call this right after `openHeader()`, INSTEAD of
     * `prepareEncrypted()`: the persisted secretstream state already
     * encodes the unwrapped symmetric key, so the private key is not
     * needed (and not consulted) on resume.
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

    /**
     * Read $bytes of plaintext, pulling and decrypting more secretstream
     * chunks as needed.
     */
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
