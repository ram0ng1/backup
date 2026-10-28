<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Settings;

use Flarum\Settings\SettingsRepositoryInterface;
use Throwable;

/**
 * Guarda antes do restore as chaves de `settings` que descrevem ESTE
 * servidor e as regrava depois.
 *
 * O restore faz DROP/CREATE de todas as tabelas; sem isto, a configuração
 * de infraestrutura do destino (SMTP, fila `fof-redis.*`, tokens OAuth)
 * vira a da origem. Conteúdo do fórum (título, cores, permissões) continua
 * vindo do backup.
 */
class SettingsPreserver
{
    /**
     * Chave onde o admin acrescenta padrões próprios, um por linha ou
     * separados por vírgula. Sufixo `*` casa por prefixo.
     */
    public const EXTRA_KEY = 'ramon-backup.preserve_settings';

    /**
     * Padrões sempre preservados. `*` no fim casa por prefixo; sem `*` a
     * comparação é exata.
     */
    public const BASELINE = [
        'mail_*',
        'fof-redis.*',
        'ramon-backup.*',
        'ramon-backup-gdrive.*',
        'backup-gdrive.*',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Valores atuais das chaves preservadas. Chamado ANTES do restore,
     * enquanto a tabela ainda é a do destino.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        try {
            $all = $this->settings->all();
        } catch (Throwable) {
            return [];
        }

        $patterns = $this->patterns();
        $out = [];

        foreach ($all as $key => $value) {
            $key = (string) $key;
            if ($this->matches($key, $patterns)) {
                $out[$key] = $value === null ? null : (string) $value;
            }
        }

        return $out;
    }

    /**
     * Regrava o snapshot por cima da tabela recém-restaurada. Roda depois
     * do rewrite de URL para ter a última palavra sobre qualquer chave
     * que os dois toquem.
     *
     * @param  array<string, string|null> $snapshot
     * @return list<string> Chaves efetivamente regravadas.
     */
    public function reapply(array $snapshot): array
    {
        $restored = [];

        foreach ($snapshot as $key => $value) {
            try {
                $this->settings->set((string) $key, $value);
                $restored[] = (string) $key;
            } catch (Throwable) {
                /* uma chave que não regrava não pode abortar as outras */
            }
        }

        return $restored;
    }

    /**
     * Baseline mais o que o admin configurou. Lido do próprio repositório
     * porque a lista precisa ser editável sem release da extensão.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        $patterns = self::BASELINE;

        try {
            $extra = (string) ($this->settings->get(self::EXTRA_KEY) ?? '');
        } catch (Throwable) {
            $extra = '';
        }

        foreach (preg_split('/[\r\n,]+/', $extra) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $patterns[] = $line;
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param list<string> $patterns
     */
    private function matches(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($key, substr($pattern, 0, -1))) {
                    return true;
                }
                continue;
            }
            if ($key === $pattern) {
                return true;
            }
        }
        return false;
    }
}
