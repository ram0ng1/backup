<?php

namespace Ramon\Backup\Settings;

use Flarum\Settings\SettingsRepositoryInterface;
use Throwable;

/**
 * Guarda, antes do restore do banco, as chaves da tabela `settings` que
 * descrevem ESTE servidor — e as regrava depois.
 *
 * O dump inclui todas as tabelas, e o restore faz DROP/CREATE: sem esta
 * camada, um restore troca a configuração de infraestrutura do destino
 * pela da origem. Foi assim que uma fila Redis configurada pelo painel
 * (`fof-redis.*`) sumiu num restore — o pacote continuava instalado, mas
 * a configuração que o ligava tinha virado a do fórum de origem.
 *
 * O critério de "descreve o servidor, não o fórum" é o que separa o que
 * entra da lista: credenciais de SMTP, endpoint de fila, tokens OAuth do
 * destino. Conteúdo do fórum (título, cores, permissões) tem que vir do
 * backup — é justamente o que se está restaurando.
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
