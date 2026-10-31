<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Http;

use Laminas\Diactoros\CallbackStream;
use RuntimeException;

/**
 * Uma faixa de um arquivo como corpo de resposta, lida aos poucos.
 *
 * Estende `CallbackStream` só porque é o tipo que o Emitter do Flarum
 * transmite pelo `SapiStreamEmitter` (os outros corpos são convertidos em
 * string inteira). Aqui o stream é legível: o emitter chama `read()` em
 * pedaços de 8 KB e ecoa cada um, então nem um backup de vários GB passa
 * pela memória, e o caminho de Range lê exatamente `$length` bytes.
 */
class FileRangeStream extends CallbackStream
{
    /** @var resource|null */
    private $handle = null;

    private int $remaining;

    public function __construct(
        private readonly string $path,
        private readonly int $start,
        private readonly int $length
    ) {
        parent::__construct(fn () => '');
        $this->remaining = $length;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getSize(): ?int
    {
        return $this->length;
    }

    public function eof(): bool
    {
        return $this->remaining <= 0;
    }

    public function read(int $length): string
    {
        if ($this->remaining <= 0 || $length <= 0) {
            return '';
        }

        $handle = $this->open();
        $chunk = fread($handle, min($length, $this->remaining));

        if ($chunk === false || $chunk === '') {
            // Arquivo encolheu no meio do caminho: encerra em vez de girar.
            $this->remaining = 0;

            return '';
        }

        $this->remaining -= strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $out = '';
        while (! $this->eof()) {
            $chunk = $this->read(8 * 1024 * 1024);
            if ($chunk === '') {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->remaining = 0;
    }

    /** @return resource */
    private function open()
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }

        // Sem limite de tempo para arquivos grandes, mas sem
        // ignore_user_abort: aba fechada encerra o script e libera o worker.
        @set_time_limit(0);

        $handle = @fopen($this->path, 'rb');
        if ($handle === false || ($this->start > 0 && fseek($handle, $this->start) !== 0)) {
            throw new RuntimeException('Backup file could not be read.');
        }

        return $this->handle = $handle;
    }
}
