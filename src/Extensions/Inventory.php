<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Extensions;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Paths;

/**
 * Extensões instaladas neste Flarum, de `workbench/` e de `vendor/`, via
 * ExtensionManager. O export usa para a seleção por extensão; o import,
 * para saber para onde volta cada extensão restaurada.
 */
class Inventory
{
    public function __construct(
        protected ExtensionManager $extensions,
        protected Paths $paths
    ) {
    }

    /**
     * Ordenadas por id para a lista não mudar entre requisições.
     *
     * @return list<array{id: string, name: string, title: string, version: string, location: string, path: string, relative: string, enabled: bool}>
     */
    public function list(): array
    {
        $base = $this->canonicalBase();
        $items = [];

        foreach ($this->extensions->getExtensions() as $ext) {
            $abs = (string) $ext->getPath();
            $relPosix = $this->relativePosix($abs, $base);
            $location = $this->classify($relPosix);

            $items[] = [
                'id'       => (string) $ext->getId(),
                // `Extension::$name` é propriedade mágica: devolve o nome do pacote composer.
                'name'     => (string) ($ext->name ?? ''),
                'title'    => (string) ($ext->getTitle() ?: $ext->getId()),
                'version'  => (string) ($ext->getVersion() ?? ''),
                'location' => $location,
                'path'     => $abs,
                'relative' => $relPosix,
                'enabled'  => $this->extensions->isEnabled($ext->getId()),
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['id'], $b['id']));
        return $items;
    }

    /**
     * Null quando a extensão não está instalada aqui.
     *
     * @return array{id: string, name: string, title: string, version: string, location: string, path: string, relative: string, enabled: bool}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->list() as $entry) {
            if ($entry['id'] === $id) return $entry;
        }
        return null;
    }

    private function canonicalBase(): string
    {
        $base = $this->paths->base;
        $real = realpath($base);
        return $real !== false ? $real : rtrim($base, '/\\');
    }

    /**
     * Caminho relativo à raiz do Flarum, sempre com `/`, para o manifesto
     * valer em qualquer sistema operacional.
     */
    private function relativePosix(string $abs, string $base): string
    {
        $absReal = realpath($abs);
        if ($absReal !== false) $abs = $absReal;
        $abs = str_replace('\\', '/', $abs);
        $base = str_replace('\\', '/', $base);

        if (str_starts_with($abs, $base . '/')) {
            return substr($abs, strlen($base) + 1);
        }
        return $abs;
    }

    private function classify(string $relPosix): string
    {
        if (str_starts_with($relPosix, 'workbench/')) return 'workbench';
        if (str_starts_with($relPosix, 'vendor/'))    return 'vendor';
        return 'unknown';
    }
}
