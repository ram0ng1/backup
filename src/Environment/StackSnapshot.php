<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Environment;

use Ramon\Backup\Locale\Messages;

/**
 * Versão do PHP e extensões em que o backup foi gerado, gravadas no
 * cabeçalho pelo export e conferidas pelo import ANTES de qualquer escrita.
 *
 * Compara em `major.minor` porque é aí que o PHP muda a sintaxe: um
 * `vendor/` resolvido no 8.5 pode ter código que o parser do 8.3 recusa,
 * e o fórum restaurado morreria na primeira requisição.
 */
class StackSnapshot
{
    /** Chave sob a qual o retrato viaja no meta do arquivo. */
    public const META_KEY = 'stack';

    /**
     * Extensões PHP cuja ausência no destino impede o Flarum de subir.
     * Deliberadamente curta: só entram as que quebram o boot. Extensões
     * opcionais de host (redis, imagick, xdebug) ficam de fora, senão
     * qualquer diferença entre servidores viraria bloqueio.
     */
    private const CRITICAL_EXTENSIONS = [
        'pdo',
        'mbstring',
        'json',
        'openssl',
        'fileinfo',
        'tokenizer',
        'dom',
        'curl',
        'zlib',
    ];

    /**
     * O `sort()` já reindexa, então dispensa `array_values()`.
     *
     * @return array{php_version: string, php_minor: string, php_extensions: list<string>}
     */
    public static function capture(): array
    {
        $loaded = array_map('strtolower', get_loaded_extensions());
        sort($loaded, SORT_STRING);

        return [
            'php_version'    => PHP_VERSION,
            'php_minor'      => self::minor(PHP_VERSION),
            'php_extensions' => $loaded,
        ];
    }

    /**
     * String vazia quando não parece versão; o chamador trata como "sem dado"
     * e não bloqueia.
     */
    public static function minor(string $version): string
    {
        if (preg_match('/\A(\d+)\.(\d+)/', $version, $m) !== 1) {
            return '';
        }

        return $m[1].'.'.$m[2];
    }

    /**
     * Motivo para este servidor NÃO receber o arquivo, ou null.
     *
     * Vale até para restore só do banco: `extensions_enabled` reativa no
     * destino extensões que exigem a stack da origem. Arquivos antigos caem
     * no `php_version` do format_version 2; sem nenhum dos dois, o import segue.
     *
     * @param array<string, mixed> $meta
     * @param list<string>|null $hereExtensions
     */
    public static function blockingReason(array $meta, ?array $hereExtensions = null, ?Messages $messages = null): ?string
    {
        $messages ??= new Messages();
        $source = self::sourceMinor($meta);
        $here   = self::minor(PHP_VERSION);

        if ($source !== '' && $here !== '' && version_compare($here, $source, '<')) {
            return $messages->get('stack.php_too_old', ['source' => $source, 'here' => $here]);
        }

        $missing = self::missingCriticalExtensions($meta, $hereExtensions);
        if ($missing !== []) {
            return $messages->get('stack.missing_extensions', [
                'count'      => count($missing),
                'extensions' => implode(', ', $missing),
            ]);
        }

        return null;
    }

    /**
     * Extensões críticas presentes na origem e ausentes aqui.
     *
     * `$hereExtensions` existe para os testes; em produção lê o SAPI atual.
     * CLI e FPM podem ter listas diferentes no mesmo host, por isso a
     * constante só traz o que o Flarum não sobe sem.
     *
     * @param array<string, mixed> $meta
     * @param list<string>|null $hereExtensions
     * @return list<string>
     */
    public static function missingCriticalExtensions(array $meta, ?array $hereExtensions = null): array
    {
        $sourceExts = self::stack($meta)['php_extensions'] ?? null;
        if (! is_array($sourceExts)) {
            return [];
        }

        $source = array_map(fn ($e) => strtolower((string) $e), $sourceExts);
        $here   = array_map(
            fn ($e) => strtolower((string) $e),
            $hereExtensions ?? get_loaded_extensions()
        );

        $missing = [];
        foreach (self::CRITICAL_EXTENSIONS as $ext) {
            if (in_array($ext, $source, true) && ! in_array($ext, $here, true)) {
                $missing[] = $ext;
            }
        }

        return $missing;
    }

    /**
     * Avisos não bloqueantes. Subir de 8.3 para 8.5 roda, mas o
     * `composer.lock` restaurado continua resolvido para a versão antiga e
     * pede um `composer update` no destino.
     *
     * @param array<string, mixed> $meta
     * @return list<string>
     */
    public static function advisories(array $meta, ?Messages $messages = null): array
    {
        $source = self::sourceMinor($meta);
        $here   = self::minor(PHP_VERSION);

        if ($source === '' || $here === '' || $source === $here) {
            return [];
        }

        return [($messages ?? new Messages())->get('stack.composer_update', ['source' => $source, 'here' => $here])];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function stack(array $meta): array
    {
        $stack = $meta[self::META_KEY] ?? null;

        return is_array($stack) ? $stack : [];
    }

    /**
     * Aceita o retrato novo e o `php_version` solto do format_version 2.
     *
     * @param array<string, mixed> $meta
     */
    private static function sourceMinor(array $meta): string
    {
        $stack = self::stack($meta);

        $candidates = [
            $stack['php_minor'] ?? null,
            $stack['php_version'] ?? null,
            $meta['php_version'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && self::minor($candidate) !== '') {
                return self::minor($candidate);
            }
        }

        return '';
    }
}
