<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\AnalysisConfiguration;

/**
 * Pins the {@see AnalysisConfiguration::fromArray()} path-resolution contract
 * documented in ADR 0015 Phase 5:
 *
 * 1. `project_root` (default `.`) resolves against cwd
 * 2. `cache.dir` (default `.qmx-cache`) resolves against the resolved project root
 * 3. `namespace.composer_json` resolves the same way when non-null
 */
#[CoversClass(AnalysisConfiguration::class)]
final class AnalysisConfigurationCacheDirResolutionTest extends TestCase
{
    #[Test]
    public function itResolvesDefaultCacheDirAgainstResolvedProjectRoot(): void
    {
        $config = AnalysisConfiguration::fromArray([]);

        self::assertSame((string) getcwd() . '/.qmx-cache', $config->cacheDir->value());
        self::assertSame((string) getcwd(), $config->projectRoot->value());
    }

    #[Test]
    public function itPassesThroughAbsoluteCacheDir(): void
    {
        $config = AnalysisConfiguration::fromArray([
            'cache' => ['dir' => '/var/cache/qmx'],
        ]);

        self::assertSame('/var/cache/qmx', $config->cacheDir->value());
    }

    #[Test]
    public function itResolvesRelativeCacheDirAgainstProjectRoot(): void
    {
        $config = AnalysisConfiguration::fromArray([
            'project_root' => '/opt/project',
            'cache' => ['dir' => 'var/cache'],
        ]);

        self::assertSame('/opt/project', $config->projectRoot->value());
        self::assertSame('/opt/project/var/cache', $config->cacheDir->value());
    }

    #[Test]
    public function itResolvesComposerJsonPathAgainstProjectRoot(): void
    {
        $config = AnalysisConfiguration::fromArray([
            'project_root' => '/opt/project',
            'namespace' => ['composer_json' => 'composer.json'],
        ]);

        self::assertNotNull($config->composerJsonPath);
        self::assertSame('/opt/project/composer.json', $config->composerJsonPath->value());
    }

    #[Test]
    public function itResolvesNestedRelativeProjectRootAgainstCwd(): void
    {
        $config = AnalysisConfiguration::fromArray([
            'project_root' => 'subdir/inner',
        ]);

        self::assertSame((string) getcwd() . '/subdir/inner', $config->projectRoot->value());
    }

    #[Test]
    public function itDefaultsComposerJsonPathToNull(): void
    {
        $config = AnalysisConfiguration::fromArray([]);

        self::assertNull($config->composerJsonPath);
    }

    #[Test]
    public function itMergeReResolvesProjectRootAgainstCwd(): void
    {
        $base = AnalysisConfiguration::fromArray(['project_root' => '/opt/old']);

        $merged = $base->merge(['project_root' => '/opt/new']);

        self::assertSame('/opt/new', $merged->projectRoot->value());
    }

    #[Test]
    public function itMergeReResolvesCacheDirAgainstNewProjectRoot(): void
    {
        $base = AnalysisConfiguration::fromArray([
            'project_root' => '/opt/old',
            'cache' => ['dir' => 'cache'],
        ]);

        $merged = $base->merge([
            'project_root' => '/opt/new',
            'cache' => ['dir' => 'var/cache'],
        ]);

        self::assertSame('/opt/new', $merged->projectRoot->value());
        self::assertSame('/opt/new/var/cache', $merged->cacheDir->value());
    }
}
