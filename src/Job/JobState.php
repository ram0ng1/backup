<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Job;

use RuntimeException;

/**
 * Estado em JSON de uma exportação ou importação longa, lido e regravado a
 * cada tick. Blobs binários (estado do secretstream, buffers) vão em base64
 * sob `binary`. Sem trava: o JS dispara um tick por vez por job.
 */
class JobState
{
    private string $file;

    /** @var array<string, mixed> */
    public array $data;

    private function __construct(string $file, array $data)
    {
        $this->file = $file;
        $this->data = $data;
    }

    public static function create(string $file, array $initial): self
    {
        $state = new self($file, $initial);
        $state->save();
        return $state;
    }

    public static function load(string $file): self
    {
        if (! is_file($file)) {
            throw new RuntimeException('Job state file not found: '.$file);
        }
        $raw = @file_get_contents($file); /* leitura de arquivo local, sem URL de input; nosemgrep: flarum-v2-server-side-fetch */
        if ($raw === false) {
            throw new RuntimeException('Could not read job state.');
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('Job state file is not valid JSON.');
        }
        return new self($file, $data);
    }

    public function save(): void
    {
        $tmp = $this->file . '.tmp';
        $encoded = json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode job state.');
        }
        if (@file_put_contents($tmp, $encoded) === false) {
            throw new RuntimeException('Could not write job state.');
        }
        // rename() é atômico no POSIX; no Windows é só "melhor esforço", o que
        // basta com um único escritor e um único leitor.
        if (! @rename($tmp, $this->file)) {
            if (! @copy($tmp, $this->file)) {
                throw new RuntimeException('Could not commit job state.');
            }
            @unlink($tmp);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Guarda em base64 sob `binary.<key>` para o JSON continuar válido.
     */
    public function setBinary(string $key, string $value): void
    {
        $this->data['binary'] = $this->data['binary'] ?? [];
        $this->data['binary'][$key] = base64_encode($value);
    }

    public function getBinary(string $key): ?string
    {
        if (! isset($this->data['binary'][$key])) return null;
        $decoded = base64_decode($this->data['binary'][$key], true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * Apaga os blobs binários. O estado do secretstream decifra o resto do
     * arquivo, então ele sai do job.json assim que o job termina.
     */
    public function forgetBinary(): void
    {
        unset($this->data['binary']);
    }

    public function file(): string
    {
        return $this->file;
    }
}
