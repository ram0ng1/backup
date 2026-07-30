<?php

namespace Ramon\Backup\Environment;

/**
 * Retrato da stack PHP em que um backup foi gerado — gravado no
 * cabeçalho do arquivo pelo export e conferido pelo import ANTES de
 * qualquer escrita.
 *
 * A comparação é feita em `major.minor` porque é nessa granularidade
 * que o PHP introduz sintaxe nova. Uma árvore de `vendor/` resolvida
 * sob 8.5 pode conter construções que o parser do 8.3 recusa, e o
 * `composer.lock` que viaja no arquivo aponta exatamente para essas
 * versões. Restaurar por cima troca um fórum vivo por um fatal na
 * primeira requisição, sem etapa intermediária onde alguém perceba.
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
     * Retrato desta origem. A lista de extensões sai ordenada e
     * contígua pelo próprio `sort()`, que reindexa — envolver em
     * `array_values()` seria chamada sem efeito.
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
     * Reduz uma versão completa ao par `major.minor`. Devolve string
     * vazia quando o valor não tem forma de versão — o chamador trata
     * isso como "sem dado" e não bloqueia.
     */
    public static function minor(string $version): string
    {
        if (preg_match('/\A(\d+)\.(\d+)/', $version, $m) !== 1) {
            return '';
        }

        return $m[1].'.'.$m[2];
    }

    /**
     * Motivo pelo qual este servidor NÃO pode receber o arquivo, ou
     * null quando a restauração pode seguir.
     *
     * O bloqueio vale para qualquer seleção, inclusive só-banco: a
     * linha `extensions_enabled` da tabela `settings` reativa no
     * destino extensões que exigem a stack da origem, então restaurar
     * apenas o banco derruba o fórum do mesmo jeito.
     *
     * Arquivos gerados antes deste campo existir ainda são cobertos
     * pelo `php_version` que o meta de format_version 2 já gravava.
     * Sem nenhum dos dois não há o que comparar e o import segue.
     *
     * @param array<string, mixed> $meta
     * @param list<string>|null $hereExtensions
     */
    public static function blockingReason(array $meta, ?array $hereExtensions = null): ?string
    {
        $source = self::sourceMinor($meta);
        $here   = self::minor(PHP_VERSION);

        if ($source !== '' && $here !== '' && version_compare($here, $source, '<')) {
            return sprintf(
                'Backup gerado em PHP %s; este servidor roda PHP %s. Não é possível continuar: '
                .'o código que o arquivo carrega (vendor/, extensões e o composer.lock que as '
                .'resolveu) foi montado para PHP %s e pode usar sintaxe que o parser do PHP %s '
                .'recusa — a restauração deixaria o Flarum sem subir. Atualize este servidor '
                .'para PHP %s ou superior, ou gere o backup a partir de uma instalação em PHP %s.',
                $source,
                $here,
                $source,
                $here,
                $source,
                $here
            );
        }

        $missing = self::missingCriticalExtensions($meta, $hereExtensions);
        if ($missing !== []) {
            return sprintf(
                'Não é possível continuar: este servidor não tem %s que a origem do backup '
                .'tinha — %s. Sem %s o Flarum não sobe depois da restauração. Habilite-%s no '
                .'PHP deste servidor e tente novamente.',
                count($missing) === 1 ? 'uma extensão PHP obrigatória' : 'extensões PHP obrigatórias',
                implode(', ', $missing),
                count($missing) === 1 ? 'ela' : 'elas',
                count($missing) === 1 ? 'a' : 'as'
            );
        }

        return null;
    }

    /**
     * Extensões críticas presentes na origem e ausentes aqui.
     *
     * `$hereExtensions` existe para o teste poder simular um destino
     * incompleto; em produção fica null e lê o SAPI corrente. Vale
     * notar que a lista do CLI e a do FPM divergem no mesmo host —
     * é por isso que a constante é curta e só tem itens sem os quais
     * o Flarum não sobe em nenhum SAPI.
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
     * Avisos não bloqueantes sobre a diferença de stack. Cobre o caso
     * inverso do bloqueio — subir de 8.3 para 8.5 é seguro de rodar,
     * mas o `composer.lock` restaurado continua resolvido para a
     * versão antiga e precisa de um `composer update` no destino.
     *
     * @param array<string, mixed> $meta
     * @return list<string>
     */
    public static function advisories(array $meta): array
    {
        $source = self::sourceMinor($meta);
        $here   = self::minor(PHP_VERSION);

        if ($source === '' || $here === '' || $source === $here) {
            return [];
        }

        return [sprintf(
            'O composer.lock deste backup foi resolvido em PHP %s e este servidor roda PHP %s. '
            .'Rode `composer update` na raiz da instalação para reresolver as dependências '
            .'para a versão local.',
            $source,
            $here
        )];
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
     * `major.minor` da origem, aceitando tanto o retrato novo quanto o
     * `php_version` solto dos arquivos de format_version 2.
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
