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

/**
 * O cabeçalho de um `.flarum` como a interface o vê.
 *
 * O cabeçalho é JSON em claro e viaja com o arquivo, então qualquer campo
 * pode vir com outro tipo. Um objeto no lugar de uma string chega ao
 * Mithril como vnode pronto (`{"tag": "<", ...}` vira HTML cru), por isso
 * só passam os campos que o modal mostra, já com o tipo esperado.
 */
final class ArchiveMeta
{
    private const STRING_FIELDS = [
        'created_at', 'flarum_version', 'php_version', 'source_url', 'source_dialect', 'target_dialect',
    ];

    private const COUNT_FIELDS = ['asset_count', 'storage_count', 'extension_count'];

    private const FLAG_FIELDS = ['has_composer', 'has_root_extend'];

    private const LOCATIONS = ['workbench', 'vendor', 'unknown'];

    /**
     * @param  array<array-key, mixed> $meta
     * @return array<string, mixed>
     */
    public static function forDisplay(array $meta): array
    {
        $out = [];

        if (is_int($meta['format_version'] ?? null)) {
            $out['format_version'] = $meta['format_version'];
        }

        foreach (self::STRING_FIELDS as $field) {
            if (is_string($meta[$field] ?? null)) {
                $out[$field] = $meta[$field];
            }
        }

        if (is_array($meta['contents'] ?? null)) {
            $out['contents'] = array_values(array_filter($meta['contents'], 'is_string'));
        }

        if (is_array($meta['manifest'] ?? null)) {
            $out['manifest'] = self::manifest($meta['manifest']);
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function manifest(array $manifest): array
    {
        $out = [];

        foreach (self::COUNT_FIELDS as $field) {
            if (array_key_exists($field, $manifest)) {
                $out[$field] = is_int($manifest[$field]) ? max(0, $manifest[$field]) : 0;
            }
        }

        foreach (self::FLAG_FIELDS as $field) {
            if (array_key_exists($field, $manifest)) {
                $out[$field] = $manifest[$field] === true;
            }
        }

        if (is_array($manifest['extensions'] ?? null)) {
            $out['extensions'] = [];
            foreach ($manifest['extensions'] as $ext) {
                $entry = self::extension($ext);
                if ($entry !== null) {
                    $out['extensions'][] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * Arquivos antigos listam só o nome do diretório do workbench.
     *
     * @return array{id: string, name: string, title: string, version: string, location: string}|null
     */
    private static function extension(mixed $ext): ?array
    {
        if (is_string($ext)) {
            return $ext === '' ? null : ['id' => $ext, 'name' => '', 'title' => $ext, 'version' => '', 'location' => 'workbench'];
        }

        if (! is_array($ext) || ! is_string($ext['id'] ?? null) || $ext['id'] === '') {
            return null;
        }

        $text = fn (string $key): string => is_string($ext[$key] ?? null) ? $ext[$key] : '';
        $location = $text('location');

        return [
            'id'       => $ext['id'],
            'name'     => $text('name'),
            'title'    => $text('title') !== '' ? $text('title') : $ext['id'],
            'version'  => $text('version'),
            'location' => in_array($location, self::LOCATIONS, true) ? $location : 'unknown',
        ];
    }
}
