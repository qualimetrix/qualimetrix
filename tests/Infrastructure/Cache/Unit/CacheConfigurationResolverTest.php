<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class CacheConfigurationResolverTest extends TestCase
{
    private string $root;

    #[Test]
    public function itRefusesAnEmptyDirectoryInALayerTheCommandLineOverrides(): void
    {
        $root = AbsolutePath::fromString($this->root);

        try {
            (new CacheConfigurationResolver())->resolve(LayeredDocument::of([
                ['source' => 'qmx.yaml', 'values' => ['cache.dir' => '']],
                ['source' => 'cli', 'values' => ['cache.dir' => 'cache', 'cache.enabled' => false]],
            ], $root), $root);
            self::fail('An empty lower-layer cache directory was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['cache', 'dir'], $refusal->position()?->segments);
            self::assertStringContainsString('a directory path cannot be empty', $refusal->summary());
        }
    }

    #[Test]
    public function itAppliesOwnerDefaultsAndLastOverrides(): void
    {
        $configuration = (new CacheConfigurationResolver())->resolve(LayeredDocument::of([
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

        try {
            $this->resolve('blocked/cache', enabled: true);
            self::fail('An enabled cache directory beneath a file must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('is not writable', $refusal->getMessage());
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame(['cache', 'dir'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itLeavesAMissingCacheDirectoryForTheFirstWrite(): void
    {
        $configuration = $this->resolve('nested/cache', enabled: true);

        self::assertSame($this->root . '/nested/cache', $configuration->directory->value());
        self::assertDirectoryDoesNotExist($this->root . '/nested/cache');
    }

    #[Test]
    public function itDoesNotCreateTheDefaultDirectoryWhileResolvingConfiguration(): void
    {
        $root = AbsolutePath::fromString($this->root);
        $configuration = (new CacheConfigurationResolver())->resolve(LayeredDocument::of([], $root), $root);

        self::assertTrue($configuration->enabled);
        self::assertSame($this->root . '/.qmx-cache', $configuration->directory->value());
        self::assertDirectoryDoesNotExist($this->root . '/.qmx-cache');
    }

    #[Test]
    public function itDisablesAnUnusableDefaultDirectoryWithAReason(): void
    {
        $projectFile = $this->root . '/not-a-directory';
        touch($projectFile);
        $projectRoot = AbsolutePath::fromString($projectFile);

        try {
            $configuration = (new CacheConfigurationResolver())->resolve(LayeredDocument::of([], $projectRoot), $projectRoot);
        } catch (ConfigurationRefusal $failure) {
            self::fail('An unusable default cache directory should disable caching: ' . $failure->summary());
        }

        self::assertFalse($configuration->enabled);
        self::assertNotEmpty($configuration->disabledBecause);
        self::assertDirectoryDoesNotExist($projectFile . '/.qmx-cache');
    }

    #[Test]
    public function itDisablesADefaultCacheDirectoryWithoutParentSearchPermission(): void
    {
        chmod($this->root, 0600);

        try {
            if (is_executable($this->root)) {
                self::markTestSkipped('Permission bits do not block directory search in this process');
            }

            $root = AbsolutePath::fromString($this->root);
            $configuration = (new CacheConfigurationResolver())->resolve(LayeredDocument::of([], $root), $root);

            self::assertFalse($configuration->enabled);
            self::assertStringContainsString($this->root, $configuration->disabledBecause ?? '');
            self::assertDirectoryDoesNotExist($this->root . '/.qmx-cache');
        } finally {
            chmod($this->root, 0700);
        }
    }

    #[Test]
    public function itRefusesAnExplicitCacheDirectoryWithoutParentSearchPermission(): void
    {
        chmod($this->root, 0600);

        try {
            if (is_executable($this->root)) {
                self::markTestSkipped('Permission bits do not block directory search in this process');
            }

            try {
                $this->resolve('cache', enabled: true);
                self::fail('An explicit cache directory without parent search permission was accepted.');
            } catch (ConfigurationRefusal $failure) {
                self::assertSame(ConfigurationSource::ConfigFile, $failure->sources()[0]->source());
                self::assertSame(['cache', 'dir'], $failure->position()?->segments);
                self::assertStringContainsString($this->root, $failure->summary());
            }
            self::assertDirectoryDoesNotExist($this->root . '/cache');
        } finally {
            chmod($this->root, 0700);
        }
    }

    #[Test]
    public function itRefusesAnExplicitCacheDirectoryThroughAPlaceableLink(): void
    {
        $swappable = $this->root . '/swappable';
        $actual = $this->root . '/actual';
        mkdir($swappable, 0777);
        chmod($swappable, 0777);
        mkdir($actual);
        symlink($actual, $swappable . '/link');

        try {
            $this->resolve('swappable/link', enabled: true);
            self::fail('An explicit cache path through a placeable link was accepted.');
        } catch (ConfigurationRefusal $failure) {
            self::assertSame(ConfigurationSource::ConfigFile, $failure->sources()[0]->source());
            self::assertSame(['cache', 'dir'], $failure->position()?->segments);
            self::assertStringContainsString('symbolic link', $failure->summary());
        }
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

    #[Test]
    public function itKeepsAnUnusableDefaultDirectoryInTheDisabledReason(): void
    {
        $projectFile = $this->root . '/not-a-directory';
        touch($projectFile);
        $projectRoot = AbsolutePath::fromString($projectFile);

        $configuration = (new CacheConfigurationResolver())->resolve(LayeredDocument::of([], $projectRoot), $projectRoot);

        self::assertFalse($configuration->enabled);
        self::assertStringContainsString($projectFile, $configuration->disabledBecause ?? '');
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

        return (new CacheConfigurationResolver())->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['cache.dir' => $directory, 'cache.enabled' => $enabled]],
        ], $root), $root);
    }
}
