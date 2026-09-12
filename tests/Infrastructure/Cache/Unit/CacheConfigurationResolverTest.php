<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;

final class CacheConfigurationResolverTest extends TestCase
{
    private string $root;

    #[Test]
    public function itAppliesOwnerDefaultsAndLastOverrides(): void
    {
        $configuration = (new CacheConfigurationResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['cache.dir' => 'var/cache', 'cache.enabled' => false]],
        ], AbsolutePath::fromString('/project')), AbsolutePath::fromString('/project'));

        self::assertSame('/project/var/cache', $configuration->directory->value());
        self::assertFalse($configuration->enabled);
    }

    /**
     * The path cannot become a directory, so the cache would have been silently
     * dead for the whole run. A file in the way rather than a mode: `mkdir`
     * fails on it whatever the process's privileges are, while a `chmod 000`
     * directory is writable to root and would make this test silently green.
     */
    #[Test]
    public function itRefusesAnEnabledCacheDirectoryThatCannotBeCreated(): void
    {
        touch($this->root . '/blocked');

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('is not writable');
        $this->resolve('blocked/cache', enabled: true);
    }

    #[Test]
    public function itCreatesAMissingCacheDirectory(): void
    {
        $configuration = $this->resolve('nested/cache', enabled: true);

        self::assertSame($this->root . '/nested/cache', $configuration->directory->value());
        self::assertDirectoryExists($this->root . '/nested/cache');
    }

    /** A cache nobody will write to is entitled to a path nobody can write to. */
    #[Test]
    public function itAcceptsAnUnusableDirectoryWhenTheCacheIsDisabled(): void
    {
        touch($this->root . '/blocked');

        $configuration = $this->resolve('blocked/cache', enabled: false);

        self::assertFalse($configuration->enabled);
        self::assertDirectoryDoesNotExist($this->root . '/blocked/cache');
    }

    /**
     * A refusal may only offer `--no-cache` where the flag actually answers it.
     *
     * The two refusals differ, and the difference is measured rather than
     * assumed: `enabled` is consulted only before the writability check, so a
     * run with `--no-cache` passes an unwritable directory (exit 2 against the
     * same directory's exit 3 without the flag) and is still stopped by an
     * empty `cache.dir`. The empty-value refusal used to offer the flag anyway
     * — advice that changed nothing about the run it was printed for.
     */
    #[Test]
    public function itOffersTheFlagOnlyWhereTheFlagWouldChangeTheOutcome(): void
    {
        touch($this->root . '/blocked');

        try {
            $this->resolve('blocked/cache', enabled: true);
            self::fail('An unwritable cache directory must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('--no-cache', $refusal->getMessage());
        }

        try {
            $this->resolve('', enabled: true);
            self::fail('An empty cache directory must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringNotContainsString('--no-cache', $refusal->getMessage());
            self::assertStringContainsString('.qmx-cache', $refusal->getMessage());
        }
    }

    /**
     * The half the refusal above relies on: a disabled cache really does skip
     * the writability check, so the advice it prints is not a dead end.
     */
    #[Test]
    public function itLetsADisabledCachePastAnUnwritableDirectory(): void
    {
        touch($this->root . '/blocked');

        $configuration = $this->resolve('blocked/cache', enabled: false);

        self::assertFalse($configuration->enabled);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-cache-resolver-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        exec(\sprintf('rm -rf %s', escapeshellarg($this->root)));
    }

    private function resolve(string $directory, bool $enabled): CacheConfiguration
    {
        $root = AbsolutePath::fromString($this->root);

        return (new CacheConfigurationResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['cache.dir' => $directory, 'cache.enabled' => $enabled]],
        ], $root), $root);
    }
}
