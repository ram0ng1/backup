<?php

namespace Ramon\Backup\Extensions;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Paths;

/**
 * Single source of truth for the extensions installed on this Flarum:
 * everything from `workbench/` (local dev) AND everything from
 * `vendor/` (composer-managed). Wraps Flarum's ExtensionManager so we
 * don't reach into its internals from controllers / jobs.
 *
 * The export and import flows both lean on this — export to populate
 * the per-extension selection UI, import to know whether each restored
 * extension belongs in `workbench/` or `vendor/`.
 */
class Inventory
{
    public function __construct(
        protected ExtensionManager $extensions,
        protected Paths $paths
    ) {
    }

    /**
     * Enumerate every installed extension. Sorted by id for stable
     * UI rendering across requests.
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
                // `Extension::$name` is a magic-property accessor that
                // returns the composer package name (e.g. "ramon/verified").
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
     * Look up one extension by id. Returns null when it is not
     * installed on this server.
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
     * Convert an absolute extension path into a forward-slash path
     * relative to the Flarum base. Always uses `/` so the manifest
     * stays portable across operating systems.
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
