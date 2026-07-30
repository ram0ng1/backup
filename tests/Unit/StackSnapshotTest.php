<?php

namespace Ramon\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\Backup\Environment\StackSnapshot;

/**
 * Guards the stack gate in {@see StackSnapshot}.
 *
 * The incident it exists for: a backup taken on PHP 8.5 was restored
 * onto a production host running 8.3. The restore reported success and
 * the result was incomplete, because nothing compared the two stacks —
 * the archive recorded `php_version` since format_version 2 and the
 * import side never read it. Restoring a newer stack over an older one
 * ships a `vendor/` tree and a `composer.lock` resolved for a PHP this
 * host cannot parse, so it must abort BEFORE the first write.
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

    /** The headline case: newer origin onto older destination must not continue. */
    public function test_blocks_when_archive_comes_from_a_newer_php(): void
    {
        $reason = StackSnapshot::blockingReason($this->metaV3($this->nextMinorUp()));

        $this->assertNotNull($reason);
        $this->assertStringContainsString($this->nextMinorUp(), $reason);
        $this->assertStringContainsString(StackSnapshot::minor(PHP_VERSION), $reason);
    }

    /**
     * Archives written before the stack snapshot existed still carry a
     * bare `php_version`, so the gate covers them too — that is exactly
     * the archive the original incident was restored from.
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

    /** Older origin runs fine, but the restored composer.lock needs re-resolving. */
    public function test_allows_older_origin_with_composer_lock_advisory(): void
    {
        $meta = $this->metaV3($this->previousMinorDown());

        $this->assertNull(StackSnapshot::blockingReason($meta));
        $advisories = StackSnapshot::advisories($meta);
        $this->assertCount(1, $advisories);
        $this->assertStringContainsString('composer update', $advisories[0]);
    }

    /**
     * No usable version data means no comparison — an ancient archive
     * must keep importing exactly as it did before the gate shipped,
     * rather than being blocked by a missing field.
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

    /** A host-specific extension the source happened to have is not a blocker. */
    public function test_optional_extensions_are_not_blockers(): void
    {
        $meta = $this->metaV3(
            StackSnapshot::minor(PHP_VERSION),
            ['pdo', 'mbstring', 'redis', 'imagick', 'xdebug']
        );

        $this->assertSame([], StackSnapshot::missingCriticalExtensions($meta, ['pdo', 'mbstring']));
        $this->assertNull(StackSnapshot::blockingReason($meta, ['pdo', 'mbstring']));
    }

    /** Case differences between SAPI listings must not read as missing. */
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
