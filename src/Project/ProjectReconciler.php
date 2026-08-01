<?php

namespace Ramon\Backup\Project;

use Flarum\Foundation\Paths;
use Throwable;

/**
 * Aplica os arquivos da raiz do projeto (`project/*` no arquivo) sobre a
 * instalação de destino — e é o único ponto do import autorizado a
 * escrever fora de assets/, storage/ e dos diretórios de extensão.
 *
 * O import extrai `project/*` para um diretório de staging dentro do job
 * em vez de gravar direto no destino. Isso existe porque sobrescrever o
 * `composer.json` do destino com o da origem é destrutivo: o manifesto
 * descreve o SERVIDOR, não o fórum. Um destino que instalou fof/redis
 * depois do backup perde o require, e o `composer install` seguinte poda
 * o pacote do vendor/ — deixando o `extend.php` da raiz apontando para
 * uma classe que não existe mais e derrubando o boot antes do handler de
 * erro existir.
 *
 * Regras:
 *   - destino SEM composer.json  → recuperação em servidor limpo; grava
 *     composer.json e composer.lock verbatim.
 *   - destino COM composer.json  → merge aditivo dos requires (o destino
 *     vence em conflito de constraint) e o composer.lock NUNCA é gravado.
 */
class ProjectReconciler
{
    /** Nomes aceitos dentro de `project/` no arquivo. */
    public const ALLOWED = ['composer.json', 'composer.lock', 'extend.php'];

    /** Seções do composer.json unidas no merge. */
    private const MERGED_MAPS = ['require', 'require-dev', 'conflict', 'replace', 'provide'];

    public function __construct(
        protected Paths $appPaths
    ) {
    }

    /**
     * Reconcilia o staging contra o destino.
     *
     * @param  string $stagingDir  Diretório com os `project/*` extraídos.
     * @param  bool   $applyComposer   Aplicar o merge do composer.json.
     * @param  bool   $applyRootExtend Sobrescrever o extend.php da raiz.
     * @return array{warnings: list<string>, applied: list<string>}
     */
    public function reconcile(string $stagingDir, bool $applyComposer, bool $applyRootExtend): array
    {
        $warnings = [];
        $applied  = [];

        $composerResult = $this->reconcileComposer($stagingDir, $applyComposer);
        $warnings = array_merge($warnings, $composerResult['warnings']);
        $applied  = array_merge($applied, $composerResult['applied']);

        $extendResult = $this->reconcileRootExtend($stagingDir, $applyRootExtend);
        $warnings = array_merge($warnings, $extendResult['warnings']);
        $applied  = array_merge($applied, $extendResult['applied']);

        return ['warnings' => $warnings, 'applied' => $applied];
    }

    /**
     * Une o manifesto do arquivo ao do destino. Sempre calcula o diff dos
     * requires — mesmo quando não vai gravar — porque saber quais pacotes
     * as extensões restauradas exigem é o que evita descobrir a falta só
     * quando o fórum não sobe.
     *
     * @return array{warnings: list<string>, applied: list<string>}
     */
    private function reconcileComposer(string $stagingDir, bool $apply): array
    {
        $incomingPath = $stagingDir.DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($incomingPath)) {
            return ['warnings' => [], 'applied' => []];
        }

        $incoming = $this->decodeJson($incomingPath);
        if ($incoming === null) {
            return [
                'warnings' => ['O composer.json do arquivo não é JSON válido — manifesto do destino mantido intacto.'],
                'applied'  => [],
            ];
        }

        $destPath = rtrim($this->appPaths->base, '/\\').DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($destPath)) {
            if (! $apply) {
                return ['warnings' => [], 'applied' => []];
            }
            return $this->installFreshManifest($stagingDir, $destPath, $incomingPath);
        }

        $current = $this->decodeJson($destPath);
        if ($current === null) {
            return [
                'warnings' => ['O composer.json deste servidor não é JSON válido — merge abortado para não piorar o estado.'],
                'applied'  => [],
            ];
        }

        $warnings = $this->requireDiffWarnings($current, $incoming);

        if (! $apply) {
            return ['warnings' => $warnings, 'applied' => []];
        }

        $merged = $this->mergeManifests($current, $incoming);

        if ($merged === $current) {
            return ['warnings' => $warnings, 'applied' => []];
        }

        $encoded = json_encode(
            $merged,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            return [
                'warnings' => array_merge($warnings, ['Falha ao serializar o composer.json unido — manifesto do destino mantido intacto.']),
                'applied'  => [],
            ];
        }

        $this->backup($destPath);
        if (@file_put_contents($destPath, $encoded."\n", LOCK_EX) === false) {
            return [
                'warnings' => array_merge($warnings, ['Não foi possível gravar o composer.json unido (permissão?).']),
                'applied'  => [],
            ];
        }

        $warnings[] = 'composer.json unido ao do destino (nada foi removido). '
            .'O composer.lock do arquivo NÃO foi aplicado: um lock de outro servidor poda pacotes '
            .'que só existem aqui. Rode `composer update --lock` para regravar o lock local.';

        return ['warnings' => $warnings, 'applied' => ['composer.json']];
    }

    /**
     * Servidor sem manifesto é recuperação em máquina limpa: aí o par
     * composer.json + composer.lock da origem é exatamente o estado
     * desejado e vai verbatim.
     *
     * @return array{warnings: list<string>, applied: list<string>}
     */
    private function installFreshManifest(string $stagingDir, string $destPath, string $incomingPath): array
    {
        $applied = [];
        if (@copy($incomingPath, $destPath)) {
            $applied[] = 'composer.json';
        }

        $lock = $stagingDir.DIRECTORY_SEPARATOR.'composer.lock';
        $destLock = rtrim($this->appPaths->base, '/\\').DIRECTORY_SEPARATOR.'composer.lock';
        if (is_file($lock) && ! is_file($destLock) && @copy($lock, $destLock)) {
            $applied[] = 'composer.lock';
        }

        return [
            'warnings' => ['Servidor sem composer.json — manifesto e lock do arquivo aplicados verbatim. Rode `composer install`.'],
            'applied'  => $applied,
        ];
    }

    /**
     * Diferença entre os requires dos dois manifestos, em linguagem de
     * operador. O lado "só no destino" é o que um overwrite cego teria
     * apagado; o lado "só no arquivo" é o que falta instalar para as
     * extensões restauradas subirem.
     *
     * @param  array<string, mixed> $current
     * @param  array<string, mixed> $incoming
     * @return list<string>
     */
    private function requireDiffWarnings(array $current, array $incoming): array
    {
        $here  = $this->requireMap($current);
        $there = $this->requireMap($incoming);

        $warnings = [];

        $onlyHere = array_diff_key($here, $there);
        if ($onlyHere !== []) {
            $warnings[] = sprintf(
                'Pacotes que existem só neste servidor e NÃO estavam no backup (preservados pelo merge): %s. '
                .'Confirme que o extend.php da raiz e as extensões restauradas continuam compatíveis com eles.',
                $this->summarise(array_keys($onlyHere))
            );
        }

        $onlyThere = array_diff_key($there, $here);
        if ($onlyThere !== []) {
            $warnings[] = sprintf(
                'Pacotes exigidos pelo backup e ausentes deste servidor: %s. '
                .'Rode `composer install` para materializá-los antes de considerar a migração concluída.',
                $this->summarise(array_keys($onlyThere))
            );
        }

        return $warnings;
    }

    /**
     * Aplica o extend.php da raiz vindo do arquivo. Fica atrás de opt-in
     * explícito porque esse arquivo é configuração do servidor de destino
     * tanto quanto do fórum de origem — e o anterior sempre vira .bak.
     *
     * @return array{warnings: list<string>, applied: list<string>}
     */
    private function reconcileRootExtend(string $stagingDir, bool $apply): array
    {
        $incoming = $stagingDir.DIRECTORY_SEPARATOR.'extend.php';
        if (! $apply || ! is_file($incoming)) {
            return ['warnings' => [], 'applied' => []];
        }

        $destPath = rtrim($this->appPaths->base, '/\\').DIRECTORY_SEPARATOR.'extend.php';
        $this->backup($destPath);

        if (! @copy($incoming, $destPath)) {
            return [
                'warnings' => ['Não foi possível gravar o extend.php da raiz (permissão?).'],
                'applied'  => [],
            ];
        }

        return [
            'warnings' => ['extend.php da raiz substituído pelo do backup (cópia do anterior salva como .bak-*).'],
            'applied'  => ['extend.php'],
        ];
    }

    /**
     * Varre o extend.php da raiz procurando classes referenciadas que o
     * autoloader não resolve.
     *
     * Existe por causa do modo de falha que motivou esta camada: o core
     * dá `require` nesse arquivo dentro de `Site::fromPaths()`, antes de
     * qualquer handler de erro — uma classe ausente vira fatal com exit
     * 255, saída vazia e log do Flarum vazio. Detectar aqui troca o 500
     * mudo por um aviso nomeando a classe.
     *
     * Análise estática: o arquivo NUNCA é executado (dar `require` nele
     * teria efeito colateral). A checagem usa o autoloader vivo, então
     * enxerga o vendor/ como está no disco agora — não o que sobrará
     * depois de um `composer install`.
     *
     * @return list<string> FQCNs não resolvidos.
     */
    public function danglingClassReferences(): array
    {
        $path = rtrim($this->appPaths->base, '/\\').DIRECTORY_SEPARATOR.'extend.php';
        if (! is_file($path)) {
            return [];
        }

        $source = $this->readLocalFile($path);
        if ($source === null || $source === '') {
            return [];
        }

        try {
            $candidates = $this->referencedClasses($source);
        } catch (Throwable) {
            return [];
        }

        $missing = [];
        foreach ($candidates as $fqcn) {
            if (! class_exists($fqcn) && ! interface_exists($fqcn)) {
                $missing[] = $fqcn;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * FQCNs citados em `new X(...)` e `X::` no código, resolvidos contra
     * o `namespace` e os `use` do arquivo.
     *
     * @return list<string>
     */
    private function referencedClasses(string $source): array
    {
        $tokens = token_get_all($source);
        $count  = count($tokens);

        $namespace = '';
        $aliases   = [];
        $names     = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = trim($this->readName($tokens, $i, $count), '\\');
                continue;
            }

            if ($token[0] === T_USE) {
                $this->collectAliases($tokens, $i, $count, $aliases);
                continue;
            }

            if ($token[0] === T_NEW) {
                $name = $this->readName($tokens, $i, $count);
                if ($name !== '') {
                    $names[] = $name;
                }
                continue;
            }

            if ($token[0] === T_DOUBLE_COLON) {
                $name = $this->readNameBackwards($tokens, $i);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $resolved = [];
        foreach ($names as $name) {
            $fqcn = $this->resolveName($name, $namespace, $aliases);
            if ($fqcn !== '') {
                $resolved[] = $fqcn;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Lê o próximo nome de classe a partir de $i, pulando espaços. Só
     * grupos `use A\{B, C}` são ignorados — não aparecem em extend.php
     * real e resolvê-los não pagaria o custo.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function readName(array $tokens, int $i, int $count): string
    {
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                return (string) $t[1];
            }
            return '';
        }
        return '';
    }

    /**
     * Lê o nome imediatamente antes de um `::`.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function readNameBackwards(array $tokens, int $i): string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                return (string) $t[1];
            }
            return '';
        }
        return '';
    }

    /**
     * Preenche o mapa alias → FQCN a partir de um `use A\B\C as D;`.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     * @param array<string, string> $aliases
     */
    private function collectAliases(array $tokens, int $i, int $count, array &$aliases): void
    {
        $fqcn = '';
        $alias = '';
        $sawAs = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === ';' || $t === '{' || $t === '(') {
                break;
            }
            if (! is_array($t)) {
                continue;
            }
            if ($t[0] === T_AS) {
                $sawAs = true;
                continue;
            }
            if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                if ($sawAs) {
                    $alias = (string) $t[1];
                } elseif ($fqcn === '') {
                    $fqcn = (string) $t[1];
                }
            }
        }

        if ($fqcn === '') {
            return;
        }
        $fqcn = ltrim($fqcn, '\\');
        if ($alias === '') {
            $parts = explode('\\', $fqcn);
            $alias = (string) end($parts);
        }
        $aliases[strtolower($alias)] = $fqcn;
    }

    /**
     * Resolve um nome como o PHP resolveria: `\A\B` é absoluto, o
     * primeiro segmento pode bater num `use`, e o resto herda o
     * namespace do arquivo.
     *
     * @param array<string, string> $aliases
     */
    private function resolveName(string $name, string $namespace, array $aliases): string
    {
        if ($name === '' || in_array(strtolower($name), ['self', 'static', 'parent', 'class'], true)) {
            return '';
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $head = strtolower($segments[0]);

        if (isset($aliases[$head])) {
            $segments[0] = $aliases[$head];
            return implode('\\', $segments);
        }

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * União das seções de mapa e de `repositories`. O destino sempre
     * vence: uma constraint local mais estrita costuma ser um pin de
     * segurança e reverter isso silenciosamente é regressão.
     *
     * @param  array<string, mixed> $current
     * @param  array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeManifests(array $current, array $incoming): array
    {
        $merged = $current;

        foreach (self::MERGED_MAPS as $section) {
            $here  = is_array($current[$section] ?? null) ? $current[$section] : [];
            $there = is_array($incoming[$section] ?? null) ? $incoming[$section] : [];
            if ($here === [] && $there === []) {
                continue;
            }
            $merged[$section] = $here + $there;
        }

        $mergedRepos = $this->mergeRepositories(
            is_array($current['repositories'] ?? null) ? $current['repositories'] : [],
            is_array($incoming['repositories'] ?? null) ? $incoming['repositories'] : []
        );
        if ($mergedRepos !== []) {
            $merged['repositories'] = $mergedRepos;
        }

        return $merged;
    }

    /**
     * Repositórios são uma lista, não um mapa: a identidade usada para
     * deduplicar é o JSON canônico da entrada.
     *
     * @param  array<mixed> $here
     * @param  array<mixed> $there
     * @return list<mixed>
     */
    private function mergeRepositories(array $here, array $there): array
    {
        $out  = [];
        $seen = [];

        foreach ([array_values($here), array_values($there)] as $set) {
            foreach ($set as $repo) {
                $key = json_encode($repo);
                if ($key === false || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $repo;
            }
        }

        return $out;
    }

    /**
     * Requires de um manifesto, sem as pseudo-dependências de plataforma
     * (`php`, `ext-*`, `lib-*`) que só poluiriam o diff.
     *
     * @param  array<string, mixed> $manifest
     * @return array<string, string>
     */
    private function requireMap(array $manifest): array
    {
        $raw = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        $out = [];
        foreach ($raw as $package => $constraint) {
            $package = (string) $package;
            if ($package === 'php' || str_starts_with($package, 'ext-') || str_starts_with($package, 'lib-')) {
                continue;
            }
            $out[$package] = (string) $constraint;
        }
        return $out;
    }

    /**
     * Lista truncada para o aviso não virar um parágrafo ilegível quando
     * o diff é grande.
     *
     * @param list<string> $items
     */
    private function summarise(array $items): string
    {
        sort($items);
        $shown = array_slice($items, 0, 8);
        $rest  = count($items) - count($shown);
        return implode(', ', $shown).($rest > 0 ? sprintf(' (+%d)', $rest) : '');
    }

    /**
     * Cópia datada antes de qualquer escrita destrutiva na raiz. Falha
     * silenciosa é aceitável: é rede de segurança, não pré-requisito.
     */
    private function backup(string $path): void
    {
        if (! is_file($path)) {
            return;
        }
        @copy($path, $path.'.bak-'.date('Ymd-His'));
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(string $path): ?array
    {
        $raw = $this->readLocalFile($path);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lê um arquivo local do próprio install: o manifesto da raiz, o
     * extend.php da raiz, ou o staging deste job.
     *
     * O caminho deriva SEMPRE de {@see Paths} ou do diretório do job —
     * nunca de input de requisição —, então o alerta de SSRF do semgrep
     * é falso positivo. Concentrar a leitura aqui deixa a supressão num
     * ponto só, em vez de espalhada por cada chamada.
     */
    private function readLocalFile(string $path): ?string
    {
        $raw = @file_get_contents($path); /* caminho derivado de Paths, nunca de request; nosemgrep: flarum-v2-server-side-fetch */
        return $raw === false ? null : $raw;
    }
}
