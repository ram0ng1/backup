<?php

namespace Ramon\Backup\Job;

use RuntimeException;

/**
 * Simple JSON-backed state file for a long-running export or import.
 *
 * Each tick of the JS-driven progress loop:
 *   1. `JobState::load($file)` — read previous progress
 *   2. The tick worker mutates the state in PHP
 *   3. `$state->save()` — atomic-rename the JSON back to disk
 *
 * Any binary blobs (the libsodium secretstream state, partial buffers
 * from the encrypted writer) are stored base64-encoded under the
 * `binary` key so the JSON stays UTF-8.
 *
 * No file locking is needed because each job ID is single-threaded by
 * convention: the JS only fires one tick at a time per job.
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
        $raw = @file_get_contents($file);
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
        // rename() is atomic on POSIX; on Windows it's "best effort" but
        // good enough for our trust level (single-writer, single-reader).
        if (! @rename($tmp, $this->file)) {
            // Fallback: copy + unlink.
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
     * Persist a binary blob (e.g. libsodium secretstream state). Stored
     * base64 under `binary.<key>` so JSON encoding stays clean.
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

    public function file(): string
    {
        return $this->file;
    }
}
