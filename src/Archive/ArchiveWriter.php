<?php

namespace Ramon\Backup\Archive;

use Ramon\Backup\Crypto\BackupCipher;
use RuntimeException;

/**
 * Streaming writer for `.flarum` archives.
 *
 * Designed for resumable, multi-tick exports:
 *   - The output file handle is opened append-mode on resume, so each
 *     tick continues writing where the previous one stopped.
 *   - Entries can be split across ticks via the `beginEntry` /
 *     `appendEntryBytes` API: write the entry header once, then push
 *     payload bytes in as many calls as needed.
 *   - Encryption state (libsodium secretstream + a small plaintext
 *     buffer) is exposed via `serializeEncryptionState()` so the caller
 *     can persist it between ticks and rebuild the writer on the next
 *     tick via `resumeEncrypted()`.
 *
 * Public surface intentionally small:
 *   - createPlain() / createEncrypted()   — first tick: write header
 *   - resumePlain() / resumeEncrypted()   — subsequent ticks: append
 *   - beginEntry($name, $type, $length)   — write entry header
 *   - appendEntryBytes($bytes)            — push payload bytes
 *   - finalize()                          — emit terminator + close stream
 */
class ArchiveWriter
{
    /** @var resource */
    private $fh;

    private bool $encrypted;

    /** Pending plaintext that hasn't been pushed through secretstream yet. */
    private string $buffer = '';

    /** libsodium secretstream state (mutated in-place by sodium_*_push). */
    private ?string $streamState = null;

    private bool $finalized = false;

    private function __construct($fh, bool $encrypted)
    {
        $this->fh = $fh;
        $this->encrypted = $encrypted;
    }

    /**
     * Start a new plaintext archive — writes the magic + flags + meta
     * header and leaves the handle positioned at the start of the entry
     * stream.
     */
    public static function createPlain(string $path, array $meta): self
    {
        $fh = self::openForCreate($path);
        self::writeFileHeader($fh, $meta, encrypted: false);
        return new self($fh, encrypted: false);
    }

    /**
     * Start a new encrypted archive — writes magic + flags + meta + the
     * 80-byte wrapped key + the secretstream init header. Returns the
     * writer with the secretstream state primed for `appendEntryBytes`.
     */
    public static function createEncrypted(string $path, array $meta, string $wrappedKey, string $symmetricKey): self
    {
        if (strlen($wrappedKey) !== BackupCipher::WRAPPED_KEY_BYTES) {
            throw new RuntimeException('Wrapped key has unexpected size.');
        }

        $fh = self::openForCreate($path);
        self::writeFileHeader($fh, $meta, encrypted: true);

        if (fwrite($fh, $wrappedKey) === false) {
            throw new RuntimeException('Could not write wrapped key.');
        }

        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($symmetricKey);
        if (fwrite($fh, $header) === false) {
            throw new RuntimeException('Could not write secretstream header.');
        }

        $w = new self($fh, encrypted: true);
        $w->streamState = $state;
        return $w;
    }

    /** Re-open an in-progress plaintext archive for append. */
    public static function resumePlain(string $path): self
    {
        $fh = self::openForAppend($path);
        return new self($fh, encrypted: false);
    }

    /** Re-open an in-progress encrypted archive, restoring stream state. */
    public static function resumeEncrypted(string $path, string $streamState, string $buffer): self
    {
        $fh = self::openForAppend($path);
        $w = new self($fh, encrypted: true);
        $w->streamState = $streamState;
        $w->buffer = $buffer;
        return $w;
    }

    /**
     * Write the header for a new entry: name + type + declared length.
     * The caller MUST follow up with exactly `$length` bytes of
     * `appendEntryBytes`. There's no central directory to fix up — the
     * declared length is what readers trust.
     */
    public function beginEntry(string $name, int $type, int $length): void
    {
        if ($this->finalized) {
            throw new RuntimeException('Cannot write after finalize().');
        }
        if ($name === '') {
            throw new RuntimeException('Entry name cannot be empty.');
        }
        $nameBytes = strlen($name);
        $header = pack('N', $nameBytes) . $name . chr($type & 0xFF) . pack('J', $length);
        $this->emit($header);
    }

    /** Push payload bytes for the current entry. */
    public function appendEntryBytes(string $bytes): void
    {
        if ($this->finalized) {
            throw new RuntimeException('Cannot write after finalize().');
        }
        if ($bytes !== '') {
            $this->emit($bytes);
        }
    }

    /**
     * Convenience: stream a resource into a single entry from start to
     * finish. Use this only when the caller is sure the work fits in
     * one tick.
     */
    public function writeEntryFromStream(string $name, int $type, $source, int $length): void
    {
        $this->beginEntry($name, $type, $length);
        $remaining = $length;
        while ($remaining > 0) {
            $want = (int) min(Format::CHUNK_SIZE, $remaining);
            $chunk = fread($source, $want);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unexpected end of source while writing entry "'.$name.'".');
            }
            $this->appendEntryBytes($chunk);
            $remaining -= strlen($chunk);
        }
    }

    /**
     * Write the zero-length-name terminator. For encrypted archives this
     * also pushes any pending buffer with the FINAL tag so the reader
     * gets a clean end-of-stream signal.
     */
    public function finalize(): void
    {
        if ($this->finalized) return;

        $this->emit(pack('N', 0));

        if ($this->encrypted) {
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                $this->streamState,
                $this->buffer,
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
            );
            $this->buffer = '';
            $this->writeFramedCipher($cipher);
        }

        fflush($this->fh);
        $this->finalized = true;
    }

    public function close(): void
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /**
     * Snapshot the encryption state so the caller can persist it between
     * ticks. `null` for plaintext writers.
     *
     * @return array{state: string, buffer: string}|null
     */
    public function serializeEncryptionState(): ?array
    {
        if (! $this->encrypted) return null;
        return [
            'state'  => (string) $this->streamState,
            'buffer' => $this->buffer,
        ];
    }

    /**
     * Force the pending buffer through the secretstream as one chunk so
     * no plaintext is carried across HTTP requests in the persisted job
     * state. Costs ~17 bytes per tick boundary; cheap.
     */
    public function flushEncryptedBuffer(): void
    {
        if (! $this->encrypted || $this->buffer === '') return;
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->streamState, $this->buffer);
        $this->buffer = '';
        $this->writeFramedCipher($cipher);
    }

    /**
     * Emit bytes — either directly to disk (plaintext mode) or into the
     * secretstream buffer (encrypted mode), pushing full chunks as the
     * buffer crosses CHUNK_SIZE.
     */
    private function emit(string $bytes): void
    {
        if (! $this->encrypted) {
            if (fwrite($this->fh, $bytes) === false) {
                throw new RuntimeException('Write failed.');
            }
            return;
        }

        $this->buffer .= $bytes;
        while (strlen($this->buffer) >= Format::CHUNK_SIZE) {
            $chunk = substr($this->buffer, 0, Format::CHUNK_SIZE);
            $this->buffer = substr($this->buffer, Format::CHUNK_SIZE);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->streamState, $chunk);
            $this->writeFramedCipher($cipher);
        }
    }

    private function writeFramedCipher(string $cipher): void
    {
        if (fwrite($this->fh, pack('N', strlen($cipher)).$cipher) === false) {
            throw new RuntimeException('Write failed.');
        }
    }

    private static function writeFileHeader($fh, array $meta, bool $encrypted): void
    {
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode archive metadata.');
        }
        $jsonBytes = strlen($json);
        if ($jsonBytes > Format::MAX_META_BYTES) {
            throw new RuntimeException('Archive metadata is unreasonably large.');
        }

        $flags = $encrypted ? Format::FLAG_ENCRYPTED : 0;
        $header = Format::MAGIC . chr($flags) . pack('N', $jsonBytes) . $json;

        if (fwrite($fh, $header) === false) {
            throw new RuntimeException('Could not write archive header.');
        }
    }

    private static function openForCreate(string $path)
    {
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Could not open archive for write: '.$path);
        }
        return $fh;
    }

    private static function openForAppend(string $path)
    {
        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Could not re-open archive: '.$path);
        }
        return $fh;
    }
}
