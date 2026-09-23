<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigDataNormalizer;

#[CoversClass(ConfigDataNormalizer::class)]
final class ConfigDataNormalizerTest extends TestCase
{
    #[Test]
    public function itKeepsPathsAsIs(): void
    {
        $result = ConfigDataNormalizer::normalize(['paths' => ['src']]);

        self::assertSame(['src'], $result['paths']);
    }

    #[Test]
    public function itRenamesExcludeToExcludes(): void
    {
        $result = ConfigDataNormalizer::normalize(['exclude' => ['vendor']]);

        self::assertArrayNotHasKey('exclude', $result);
        self::assertSame(['vendor'], $result['excludes']);
    }

    #[Test]
    public function itFlattensCacheSectionIntoDottedKeys(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'cache' => ['dir' => '/tmp', 'enabled' => false],
        ]);

        self::assertArrayNotHasKey('cache', $result);
        self::assertSame('/tmp', $result['cache.dir']);
        self::assertFalse($result['cache.enabled']);
    }

    #[Test]
    public function itKeepsFormatAsIs(): void
    {
        $result = ConfigDataNormalizer::normalize(['format' => 'json']);

        self::assertSame('json', $result['format']);
    }

    #[Test]
    public function itKeepsRulesUnchanged(): void
    {
        $rules = ['complexity.ccn' => ['callable' => ['warning' => 7]]];

        $result = ConfigDataNormalizer::normalize(['rules' => $rules]);

        self::assertSame($rules, $result['rules']);
    }

    #[Test]
    public function itPreservesAnAuthoredSelectorMappingForTheSelectorDecoder(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'suppressPaths' => [
                ['subtree' => 'src/Generated'],
                ['regex' => null],
            ],
        ]);

        self::assertSame(
            [
                ['subtree' => 'src/Generated'],
                ['regex' => null],
            ],
            $result['suppress_paths'],
        );
    }

    #[Test]
    public function itRenamesDisabledRulesToSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['disabledRules' => ['complexity']]);

        self::assertArrayNotHasKey('disabledRules', $result);
        self::assertSame(['complexity'], $result['disabled_rules']);
    }

    #[Test]
    public function itRenamesFailOnToSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['failOn' => 'warning']);

        self::assertArrayNotHasKey('failOn', $result);
        self::assertSame('warning', $result['fail_on']);
    }

    #[Test]
    public function itKeepsExcludeHealthKeyAsCamelCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['excludeHealth' => ['typing']]);

        self::assertSame(['typing'], $result['excludeHealth']);
    }

    #[Test]
    public function itRenamesIncludeGeneratedToSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['includeGenerated' => true]);

        self::assertTrue($result['include_generated']);
    }

    #[Test]
    public function itReturnsAnEmptyArrayForEmptyInput(): void
    {
        $result = ConfigDataNormalizer::normalize([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itDropsUnknownKeys(): void
    {
        $result = ConfigDataNormalizer::normalize(['unknownKey' => 'value']);

        self::assertSame([], $result);
    }

    #[Test]
    public function itFlattensCouplingFrameworkNamespacesWhileKeepingTheOriginalSection(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'coupling' => [
                'frameworkNamespaces' => ['Symfony', 'PhpParser', 'Psr'],
            ],
        ]);

        self::assertSame(['Symfony', 'PhpParser', 'Psr'], $result['coupling.framework_namespaces']);
        self::assertSame(
            ['frameworkNamespaces' => ['Symfony', 'PhpParser', 'Psr']],
            $result['coupling'],
        );
    }

    #[Test]
    public function itRenamesMemoryLimitToSnakeCase(): void
    {
        $result = ConfigDataNormalizer::normalize(['memoryLimit' => '1G']);

        self::assertArrayNotHasKey('memoryLimit', $result);
        self::assertSame('1G', $result['memory_limit']);
    }

    #[Test]
    public function itFlattensParallelWorkersIntoADottedKey(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'parallel' => ['workers' => 4],
        ]);

        self::assertSame(4, $result['parallel.workers']);
    }

    #[Test]
    #[TestWith(['coupling'])]
    #[TestWith(['computedMetrics'])]
    #[TestWith(['excludeHealth'])]
    #[TestWith(['architecture'])]
    public function itReadsANullDocumentRootAsAnUnwrittenKey(string $root): void
    {
        $result = ConfigDataNormalizer::normalize([$root => null]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itReadsANullEntryKeyAsAnUnwrittenKey(): void
    {
        $result = ConfigDataNormalizer::normalize(['paths' => null, 'format' => null]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itReadsANullKeyInsideACopiedSubtreeAsAnUnwrittenKeyWithoutTouchingItsSiblings(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'architecture' => ['coverage-gap' => 'ignore', 'layers' => null],
            'coupling' => ['frameworkNamespaces' => null],
            'computedMetrics' => ['health.typing' => ['enabled' => null, 'warning' => 80]],
        ]);

        self::assertSame(['coverage-gap' => 'ignore'], $result['architecture']);
        self::assertSame([], $result['coupling']);
        self::assertArrayNotHasKey('coupling.framework_namespaces', $result);
        self::assertSame(['health.typing' => ['warning' => 80]], $result['computedMetrics']);
    }

    #[Test]
    public function itReadsANullKeyAsUnwrittenAtEveryDepth(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'architecture' => [
                'layers' => [
                    ['name' => 'domain', 'patterns' => ['App\\Domain\\*'], 'pending' => null],
                ],
            ],
        ]);

        self::assertSame(
            ['layers' => [['name' => 'domain', 'patterns' => ['App\\Domain\\*']]]],
            $result['architecture'],
        );
    }

    #[Test]
    public function itKeepsFalsyValuesThatAreNotNullAtEveryDepth(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'includeGenerated' => false,
            'cache' => ['enabled' => false, 'dir' => ''],
            'excludeHealth' => [],
            'computedMetrics' => ['health.typing' => ['enabled' => false, 'warning' => 0]],
        ]);

        self::assertFalse($result['include_generated']);
        self::assertFalse($result['cache.enabled']);
        self::assertSame('', $result['cache.dir']);
        self::assertSame([], $result['excludeHealth']);
        self::assertSame(['health.typing' => ['enabled' => false, 'warning' => 0]], $result['computedMetrics']);
    }

    #[Test]
    public function itKeepsANullListElement(): void
    {
        $result = ConfigDataNormalizer::normalize(['excludeHealth' => [null, 'health.typing']]);

        self::assertSame([null, 'health.typing'], $result['excludeHealth']);
    }

    /**
     * A level-1 key of an identifier-keyed root is an entity the author named,
     * not an option with a default for `~` to fall back to. Erased here, the
     * owner never saw it: `computed.foo: ~` ran clean while `computed.foo: {}`
     * was refused.
     */
    #[Test]
    public function itKeepsANullIdentifierEntryForTheRootOwnerToJudge(): void
    {
        $result = ConfigDataNormalizer::normalize([
            'computedMetrics' => ['my-metric' => null, 'health.typing' => ['enabled' => null, 'warning' => 80]],
        ]);

        self::assertSame(['my-metric' => null, 'health.typing' => ['warning' => 80]], $result['computedMetrics']);
    }

    #[Test]
    public function itLeavesNullsInsideTheRulesSubtreeAlone(): void
    {
        $rules = [
            'complexity.ccn' => null,
            'code-smell.boolean-argument' => ['callable' => ['warning' => null]],
        ];

        $result = ConfigDataNormalizer::normalize(['rules' => $rules]);

        self::assertSame($rules, $result['rules']);
    }
}
