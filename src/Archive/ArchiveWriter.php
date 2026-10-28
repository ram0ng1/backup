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
 * Escritor em streaming de arquivos `.flarum`, feito para exports que
 * atravessam vários ticks.
 *
 * Na retomada o arquivo é reaberto em modo append, e uma entrada pode ser
 * escrita aos pedaços (`beginEntry` + `appendEntryBytes`). O estado do
 * secretstream sai por `serializeEncryptionState()` para o chamador
 * persistir e reconstruir o escritor com `resumeEncrypted()`.
 */
class ArchiveWriter
{
    /** @var resource */
    private $fh;

    private bool $encrypted;

    /** Texto claro que ainda não passou pelo secretstream. */
    private string $buffer = '';

    /** Estado do secretstream, alterado no lugar por sodium_*_push. */
    private ?string $streamState = null;

    private bool $finalized = false;

    /** @param resource $fh */
    private function __construct($fh, bool $encrypted)
    {
        $this->fh = $fh;
        $this->encrypted = $encrypted;
    }

    /** Grava magic, flags e meta; o handle fica no início das entradas. */
    public static function createPlain(string $path, array $meta): self
    {
        $fh = self::openForCreate($path);
        self::writeFileHeader($fh, $meta, encrypted: false);
        return new self($fh, encrypted: false);
    }

    /**
     * Grava o cabeçalho, a chave embrulhada (80 bytes) e o header do
     * secretstream, deixando o estado pronto para `appendEntryBytes`.
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

    /** Reabre um arquivo sem cifra em andamento para append. */
    public static function resumePlain(string $path): self
    {
        $fh = self::openForAppend($path);
        return new self($fh, encrypted: false);
    }

    /** Reabre um arquivo cifrado em andamento, restaurando o estado do stream. */
    public static function resumeEncrypted(string $path, string $streamState, string $buffer): self
    {
        $fh = self::openForAppend($path);
        $w = new self($fh, encrypted: true);
        $w->streamState = $streamState;
        $w->buffer = $buffer;
        return $w;
    }

    /**
     * O chamador TEM de mandar exatamente `$length` bytes depois. Não há
     * diretório central para corrigir: o leitor confia no tamanho declarado.
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
     * Entrada inteira de uma vez. Só para quando cabe com folga num tick.
     *
     * @param resource $source
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
     * Grava o terminador. Em arquivo cifrado empurra o resto do buffer com a
     * tag FINAL, para o leitor reconhecer o fim do stream.
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
     * `null` em arquivo sem cifra.
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
     * Empurra o buffer pendente como um chunk, para nenhum texto claro ir
     * parar no estado persistido do job. Custa ~17 bytes por tick.
     */
    public function flushEncryptedBuffer(): void
    {
        if (! $this->encrypted || $this->buffer === '') return;
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->streamState, $this->buffer);
        $this->buffer = '';
        $this->writeFramedCipher($cipher);
    }

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

    /**
     * @param resource $fh
     * @param array<string, mixed> $meta
     */
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

    /** @return resource */
    private static function openForCreate(string $path)
    {
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Could not open archive for write: '.$path);
        }
        return $fh;
    }

    /** @return resource */
    private static function openForAppend(string $path)
    {
        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Could not re-open archive: '.$path);
        }
        return $fh;
    }
}
