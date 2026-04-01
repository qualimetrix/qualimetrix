<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Pipeline\ConfigDataNormalizer;

#[CoversClass(ConfigDataNormalizer::class)]
final class ConfigDataNormalizerTest extends TestCase
{
    #[Test]
    public function normalizesPathsDirectly(): void
    {
        $result = ConfigDataNormalizer::normalize(['paths' => ['src']]);

        self::assertSame(['src'], $result['paths']);
    }

    #[Test]
    public function normalizesExcludeToExcludes(): void
    {
        $result = ConfigDataNormalizer::normalize(['exclude' => ['vendor']]);

        self::assertArrayNotHasKey('exclude', $result);
        self::assertSame(['vendor'], $result['excludes']);
    }

    #[Test]
    public function normalizesCacheSection(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'cache' => ['dir' => '/tmp', 'enabled' => false],
        ]);

        self::assertArrayNotHasKey('cache', $result);
        self::assertSame('/tmp', $result['cache.dir']);
        self::assertFalse($result['cache.enabled']);
    }

    #[Test]
    public function normalizesFormatDirectly(): void
    {
        $result = ConfigDataNormalizer::normalize(['format' => 'json']);

        self::assertSame('json', $result['format']);
    }

    #[Test]
    public function normalizesNamespaceSection(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'namespace' => ['strategy' => 'psr4', 'composerJson' => 'path'],
        ]);

        self::assertArrayNotHasKey('namespace', $result);
        self::assertSame('psr4', $result['namespace.strategy']);
        self::assertSame('path', $result['namespace.composer_json']);
    }

    #[Test]
    public function normalizesAggregationSection(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'aggregation' => ['prefixes' => ['App\\'], 'autoDepth' => 2],
        ]);

        self::assertArrayNotHasKey('aggregation', $result);
        self::assertSame(['App\\'], $result['aggregation.prefixes']);
        self::assertSame(2, $result['aggregation.auto_depth']);
    }

    #[Test]
    public function normalizesRulesAsIs(): void
    {
        $rules = ['complexity.cyclomatic' => ['method' => ['warning' => 7]]];

        $result = ConfigDataNormalizer::normalize(['rules' => $rules]);

        self::assertSame($rules, $result['rules']);
    }

    #[Test]
    public function normalizesDisabledRules(): void
    {
        $result = ConfigDataNormalizer::normalize(['disabledRules' => ['complexity']]);

        self::assertArrayNotHasKey('disabledRules', $result);
        self::assertSame(['complexity'], $result['disabled_rules']);
    }

    #[Test]
    public function normalizesFailOn(): void
    {
        $result = ConfigDataNormalizer::normalize(['failOn' => 'warning']);

        self::assertArrayNotHasKey('failOn', $result);
        self::assertSame('warning', $result['fail_on']);
    }

    #[Test]
    public function normalizesExcludeHealthFromCamelCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['excludeHealth' => ['typing']]);

        self::assertSame(['typing'], $result['exclude_health']);
    }

    #[Test]
    public function normalizesExcludeHealthFromSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['exclude_health' => ['complexity']]);

        self::assertSame(['complexity'], $result['exclude_health']);
    }

    #[Test]
    public function normalizesIncludeGeneratedFromCamelCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['includeGenerated' => true]);

        self::assertTrue($result['include_generated']);
    }

    #[Test]
    public function normalizesIncludeGeneratedFromSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['include_generated' => true]);

        self::assertTrue($result['include_generated']);
    }

    #[Test]
    public function returnsEmptyArrayForEmptyInput(): void
    {
        $result = ConfigDataNormalizer::normalize([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function ignoresUnknownKeys(): void
    {
        $result = ConfigDataNormalizer::normalize(['unknownKey' => 'value']);

        self::assertSame([], $result);
    }

    #[Test]
    public function normalizesCouplingFrameworkNamespaces(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'coupling' => [
                'frameworkNamespaces' => ['Symfony', 'PhpParser', 'Psr'],
            ],
        ]);

        self::assertSame(['Symfony', 'PhpParser', 'Psr'], $result['coupling.framework_namespaces']);
    }

    #[Test]
    public function normalizesCouplingFrameworkNamespacesSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'coupling' => [
                'framework_namespaces' => ['Symfony'],
            ],
        ]);

        self::assertSame(['Symfony'], $result['coupling.framework_namespaces']);
    }

    #[Test]
    public function normalizesMemoryLimitFromCamelCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['memoryLimit' => '1G']);

        self::assertArrayNotHasKey('memoryLimit', $result);
        self::assertSame('1G', $result['memory_limit']);
    }

    #[Test]
    public function normalizesMemoryLimitFromSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['memory_limit' => '512M']);

        self::assertSame('512M', $result['memory_limit']);
    }
}
