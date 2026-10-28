<?php

/*
 * This file is part of ramon/backup.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Backup\Tests\unit;

use PHPUnit\Framework\TestCase;
use Ramon\Backup\Environment\StackSnapshot;

/**
 * Bloqueio por stack do {@see StackSnapshot}.
 *
 * Um backup feito em PHP 8.5 foi restaurado num servidor com 8.3: o
 * restore deu sucesso e o resultado ficou incompleto, porque nada
 * comparava as stacks. O `vendor/` e o `composer.lock` vêm resolvidos para
 * um PHP que este servidor não interpreta, então o restore tem que parar
 * ANTES da primeira escrita.
 */
final class StackSnapshotTest extends TestCase
{
    private function metaV3(string $minor, ?array $extensions = null): array
    {
        $stack = ['php_minor' => $minor, 'php_version' => $minor.'.0'];
        if ($extensions !== null) {
            $stack['php_extensions'] = $extensions;
        }

        return [StackSnapshot::META_KEY => $stack];
    }

    private function nextMinorUp(): string
    {
        [$major, $minor] = explode('.', StackSnapshot::minor(PHP_VERSION));

        return $major.'.'.((int) $minor + 1);
    }

    private function previousMinorDown(): string
    {
        [$major, $minor] = explode('.', StackSnapshot::minor(PHP_VERSION));

        return $major.'.'.max(0, (int) $minor - 1);
    }

    public function test_minor_reduces_full_versions(): void
    {
        $this->assertSame('8.5', StackSnapshot::minor('8.5.4'));
        $this->assertSame('8.3', StackSnapshot::minor('8.3.8-nts'));
        $this->assertSame('8.4', StackSnapshot::minor('8.4'));
        $this->assertSame('', StackSnapshot::minor('not-a-version'));
        $this->assertSame('', StackSnapshot::minor(''));
    }

    /** Origem mais nova sobre destino mais antigo não pode continuar. */
    public function test_blocks_when_archive_comes_from_a_newer_php(): void
    {
        $reason = StackSnapshot::blockingReason($this->metaV3($this->nextMinorUp()));

        $this->assertNotNull($reason);
        $this->assertStringContainsString($this->nextMinorUp(), $reason);
        $this->assertStringContainsString(StackSnapshot::minor(PHP_VERSION), $reason);
    }

    /**
     * Arquivos anteriores ao snapshot de stack ainda trazem `php_version`
     * solto, e o bloqueio vale para eles também.
     */
    public function test_blocks_on_legacy_v2_meta_with_only_php_version(): void
    {
        $this->assertNotNull(StackSnapshot::blockingReason([
            'php_version' => $this->nextMinorUp().'.1',
        ]));
    }

    public function test_allows_same_minor_and_reports_no_advisory(): void
    {
        $meta = $this->metaV3(StackSnapshot::minor(PHP_VERSION));

        $this->assertNull(StackSnapshot::blockingReason($meta));
        $this->assertSame([], StackSnapshot::advisories($meta));
    }

    /** Origem mais antiga roda, mas o composer.lock restaurado precisa ser resolvido de novo. */
    public function test_allows_older_origin_with_composer_lock_advisory(): void
    {
        $meta = $this->metaV3($this->previousMinorDown());

        $this->assertNull(StackSnapshot::blockingReason($meta));
        $advisories = StackSnapshot::advisories($meta);
        $this->assertCount(1, $advisories);
        $this->assertStringContainsString('composer update', $advisories[0]);
    }

    /**
     * Sem versão utilizável não há comparação: um arquivo antigo continua
     * importando como antes, em vez de ser barrado por campo ausente.
     */
    public function test_absent_or_malformed_metadata_never_blocks(): void
    {
        $this->assertNull(StackSnapshot::blockingReason([]));
        $this->assertNull(StackSnapshot::blockingReason(['php_version' => 42]));
        $this->assertNull(StackSnapshot::blockingReason([StackSnapshot::META_KEY => 'nonsense']));
        $this->assertSame([], StackSnapshot::advisories([]));
    }

    public function test_blocks_when_destination_lost_a_critical_extension(): void
    {
        $meta = $this->metaV3(
            StackSnapshot::minor(PHP_VERSION),
            ['pdo', 'mbstring', 'json', 'openssl', 'fileinfo', 'tokenizer', 'dom']
        );

        $missing = StackSnapshot::missingCriticalExtensions($meta, ['mbstring', 'json']);
        $this->assertContains('pdo', $missing);
        $this->assertContains('dom', $missing);

        $reason = StackSnapshot::blockingReason($meta, ['mbstring', 'json']);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('pdo', $reason);
    }

    /** Extensão de PHP específica do servidor de origem não bloqueia. */
    public function test_optional_extensions_are_not_blockers(): void
    {
        $meta = $this->metaV3(
            StackSnapshot::minor(PHP_VERSION),
            ['pdo', 'mbstring', 'redis', 'imagick', 'xdebug']
        );

        $this->assertSame([], StackSnapshot::missingCriticalExtensions($meta, ['pdo', 'mbstring']));
        $this->assertNull(StackSnapshot::blockingReason($meta, ['pdo', 'mbstring']));
    }

    /** Diferença de caixa entre as listagens do SAPI não conta como ausência. */
    public function test_extension_comparison_is_case_insensitive(): void
    {
        $meta = $this->metaV3(StackSnapshot::minor(PHP_VERSION), ['PDO', 'Mbstring']);

        $this->assertSame([], StackSnapshot::missingCriticalExtensions($meta, ['pdo', 'mbstring']));
    }

    public function test_capture_records_a_comparable_snapshot(): void
    {
        $snapshot = StackSnapshot::capture();

        $this->assertSame(PHP_VERSION, $snapshot['php_version']);
        $this->assertSame(StackSnapshot::minor(PHP_VERSION), $snapshot['php_minor']);
        $this->assertContains('pdo', $snapshot['php_extensions']);
        $this->assertSame(array_values($snapshot['php_extensions']), $snapshot['php_extensions']);
        $this->assertNull(StackSnapshot::blockingReason([StackSnapshot::META_KEY => $snapshot]));
    }
}
