<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Locale;

use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Texto que o servidor devolve ao painel: progresso, avisos e erros.
 *
 * Sai no idioma da requisição pelo tradutor do Flarum, com as chaves em
 * `ramon-backup.api.*`. Sem tradutor (testes unitários) ou com a chave
 * ausente, usa o texto do locale/en.yml com a mesma formatação ICU.
 */
class Messages
{
    public const PREFIX = 'ramon-backup.api.';

    /** @var array<string, string>|null */
    private static ?array $fallback = null;

    public function __construct(
        protected ?TranslatorInterface $translator = null
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function get(string $key, array $params = []): string
    {
        $id = self::PREFIX.$key;

        if ($this->translator !== null) {
            $text = $this->translator->trans($id, $params);
            if ($text !== $id) {
                return $text;
            }
        }

        $pattern = self::fallback()[$id] ?? null;
        if ($pattern === null) {
            return $id;
        }

        return (new IntlFormatter())->formatIntl($pattern, 'en', $params);
    }

    /** @return array<string, string> */
    private static function fallback(): array
    {
        if (self::$fallback === null) {
            $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/locale/en.yml');
            self::$fallback = is_array($parsed) ? self::flatten($parsed) : [];
        }

        return self::$fallback;
    }

    /**
     * @param array<mixed> $tree
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            $id = $prefix.$key;
            if (is_array($value)) {
                $out += self::flatten($value, $id.'.');
            } elseif (is_scalar($value)) {
                $out[$id] = (string) $value;
            }
        }

        return $out;
    }
}
