<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;

#[CoversClass(DocumentComposer::class)]
final class ConfigDataNormalizerTest extends TestCase
{
    #[Test]
    public function itKeepsPathsAsIs(): void
    {
        $result = self::document(['paths' => ['src']]);

        self::assertSame(['src'], $result['paths']);
    }

    #[Test]
    public function itRefusesAScalarExclusionAndReadsItsDeclaredSelector(): void
    {
        $this->assertRefusal(['exclude' => ['vendor']], '"exclude[0]" in configuration file "/project/qmx.yaml" must be a map, got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.', ['exclude', '0']);
        $result = self::document(['exclude' => [['subtree' => 'vendor']]]);
        self::assertSame([['subtree' => 'vendor']], $result['exclude']);
        self::assertArrayNotHasKey('excludes', $result);
    }

    #[Test]
    public function itReadsTheDeclaredCacheFieldsWithoutTransportCopies(): void
    {
        $result = self::document(['cache' => ['dir' => '/tmp', 'enabled' => false]]);
        self::assertSame(['dir' => '/tmp', 'enabled' => false], $result['cache']);
        self::assertArrayNotHasKey('cache.dir', $result);
        self::assertArrayNotHasKey('cache.enabled', $result);
    }

    #[Test]
    public function itKeepsFormatAsIs(): void
    {
        $result = self::document(['format' => 'json']);

        self::assertSame('json', $result['format']);
    }

    #[Test]
    public function itKeepsRulesUnchanged(): void
    {
        $rules = ['complexity.ccn' => ['callable' => ['warning' => 7]]];

        $result = self::document(['rules' => $rules]);

        self::assertSame($rules, $result['rules']);
    }

    #[Test]
    public function itRefusesAnEmptySelectorAndKeepsItsLawfulSibling(): void
    {
        $this->assertRefusal(['suppressPaths' => [['subtree' => 'src/Generated'], ['regex' => null]]], 'Item 1 of "suppressPaths" in configuration file "/project/qmx.yaml" writes nothing; remove it or give it a value.', ['suppressPaths', '1']);
        $result = self::document(['suppressPaths' => [['subtree' => 'src/Generated']]]);
        self::assertSame([['subtree' => 'src/Generated']], $result['suppress_paths']);
    }

    #[Test]
    public function itRenamesDisabledRulesToSnakeCase(): void
    {
        $result = self::document(['disabledRules' => ['complexity']]);

        self::assertArrayNotHasKey('disabledRules', $result);
        self::assertSame(['complexity'], $result['disabled_rules']);
    }

    #[Test]
    public function itRenamesFailOnToSnakeCase(): void
    {
        $result = self::document(['failOn' => 'warning']);

        self::assertArrayNotHasKey('failOn', $result);
        self::assertSame('warning', $result['fail_on']);
    }

    #[Test]
    public function itKeepsExcludeHealthKeyAsCamelCase(): void
    {
        $result = self::document(['exclude_health' => ['typing']]);

        self::assertSame(['typing'], $result['exclude_health']);
    }

    #[Test]
    public function itRenamesIncludeGeneratedToSnakeCase(): void
    {
        $result = self::document(['includeGenerated' => true]);

        self::assertTrue($result['include_generated']);
    }

    #[Test]
    public function itReturnsAnEmptyArrayForEmptyInput(): void
    {
        $result = self::document([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itRefusesAnUnknownRootWithItsOriginalSpelling(): void
    {
        $this->assertRefusal(['unknownKey' => 'value'], 'Unknown key "unknownKey" in configuration file "/project/qmx.yaml". Accepted keys: exclude, suppress_paths, suppress_namespaces, include_generated, include_autoload_dev, cache, parallel, coupling, computed_metrics, exclude_health, rules, only_rules, disabled_rules, architecture, fail_on, memory_limit, paths, format.', ['unknownKey']);
    }

    #[Test]
    public function itRefusesScalarNamespaceSelectorsAndReadsTheDeclaredList(): void
    {
        $this->assertRefusal(['coupling' => ['frameworkNamespaces' => ['Symfony', 'PhpParser', 'Psr']]], '"coupling.frameworkNamespaces[0]" in configuration file "/project/qmx.yaml" must be a map, got string.', ['coupling', 'frameworkNamespaces', '0']);
        $this->assertRefusal(['coupling' => ['frameworkNamespaces' => [['subtree' => 'Symfony'], 'PhpParser', 'Psr']]], '"coupling.frameworkNamespaces[1]" in configuration file "/project/qmx.yaml" must be a map, got string.', ['coupling', 'frameworkNamespaces', '1']);
        $this->assertRefusal(['coupling' => ['frameworkNamespaces' => [['subtree' => 'Symfony'], ['subtree' => 'PhpParser'], 'Psr']]], '"coupling.frameworkNamespaces[2]" in configuration file "/project/qmx.yaml" must be a map, got string.', ['coupling', 'frameworkNamespaces', '2']);
        $result = self::document(['coupling' => ['frameworkNamespaces' => [['subtree' => 'Symfony'], ['subtree' => 'PhpParser'], ['subtree' => 'Psr']]]]);
        self::assertSame(['framework_namespaces' => [['subtree' => 'Symfony'], ['subtree' => 'PhpParser'], ['subtree' => 'Psr']]], $result['coupling']);
        self::assertArrayNotHasKey('coupling.framework_namespaces', $result);
    }

    #[Test]
    public function itRenamesMemoryLimitToSnakeCase(): void
    {
        $result = self::document(['memoryLimit' => '1G']);

        self::assertArrayNotHasKey('memoryLimit', $result);
        self::assertSame('1G', $result['memory_limit']);
    }

    #[Test]
    public function itReadsTheDeclaredParallelWorkersWithoutATransportCopy(): void
    {
        $result = self::document([
            'parallel' => ['workers' => 4],
        ]);

        self::assertSame(['workers' => 4], $result['parallel']);
        self::assertArrayNotHasKey('parallel.workers', $result);
    }

    #[Test]
    #[TestWith(['coupling'])]
    #[TestWith(['computed_metrics'])]
    #[TestWith(['exclude_health'])]
    #[TestWith(['architecture'])]
    public function itReadsANullDocumentRootAsAnUnwrittenKey(string $root): void
    {
        $result = self::document([$root => null]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itReadsANullEntryKeyAsAnUnwrittenKey(): void
    {
        $result = self::document(['paths' => null, 'format' => null]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itReadsANullKeyInsideACopiedSubtreeAsAnUnwrittenKeyWithoutTouchingItsSiblings(): void
    {
        $result = self::document([
            'architecture' => ['coverage-gap' => 'ignore', 'layers' => null],
            'coupling' => ['frameworkNamespaces' => null],
            'computed_metrics' => ['health.typing' => ['enabled' => null, 'warning' => 80]],
        ]);

        self::assertSame(['coverage-gap' => 'ignore'], $result['architecture']);
        self::assertArrayNotHasKey('coupling', $result);
        self::assertArrayNotHasKey('coupling.framework_namespaces', $result);
        self::assertSame(['health.typing' => ['warning' => 80]], $result['computed_metrics']);
    }

    #[Test]
    public function itReadsANullKeyAsUnwrittenAtEveryDepth(): void
    {
        $result = self::document([
            'architecture' => [
                'layers' => [
                    ['name' => 'domain', 'patterns' => ['App\\Domain\\*'], 'pending' => null],
                ],
            ],
        ]);

        self::assertSame(
            ['layers' => [['name' => 'domain', 'patterns' => [['App\\Domain\\*']]]]],
            $result['architecture'],
        );
    }

    #[Test]
    public function itRefusesAnEmptyDirectoryAndKeepsOtherFalsyValues(): void
    {
        $written = [
            'includeGenerated' => false,
            'cache' => ['enabled' => false, 'dir' => ''],
            'exclude_health' => [],
            'computed_metrics' => ['health.typing' => ['enabled' => false, 'warning' => 0]],
        ];
        $this->assertRefusal($written, 'Invalid value for "cache.dir": a directory path cannot be empty. Omit the key to use the default (.qmx-cache).', ['cache', 'dir']);
        $result = self::document([
            'includeGenerated' => false,
            'cache' => ['enabled' => false],
            'exclude_health' => [],
            'computed_metrics' => ['health.typing' => ['enabled' => false, 'warning' => 0]],
        ]);
        self::assertFalse($result['include_generated']);
        self::assertSame(['enabled' => false], $result['cache']);
        self::assertSame([], $result['exclude_health']);
        self::assertSame(['health.typing' => ['enabled' => false, 'warning' => 0]], $result['computed_metrics']);
    }

    #[Test]
    public function itRefusesANullListElementAndKeepsItsLawfulSibling(): void
    {
        $this->assertRefusal(['exclude_health' => [null, 'health.typing']], 'Item 0 of "exclude_health" in configuration file "/project/qmx.yaml" is null (`~`); a list item is a value, not an unwritten key — remove it or write a value.', ['exclude_health', '0']);
        $result = self::document(['exclude_health' => ['health.typing']]);
        self::assertSame(['health.typing'], $result['exclude_health']);
    }

    #[Test]
    public function itRefusesAnInvalidIdentifierEvenWhenNullAndReadsTheLawfulSibling(): void
    {
        $this->assertRefusal([
            'computed_metrics' => ['my-metric' => null, 'health.typing' => ['enabled' => null, 'warning' => 80]],
        ], 'Computed metric name "my-metric" must be "health.<name>" or "computed.<name>", where every segment is lower-case kebab (/^(?:health|computed)(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+$/) and the last segment is not the name of an aggregation strategy', ['computed_metrics', 'my-metric']);
        $result = self::document(['computed_metrics' => ['health.typing' => ['enabled' => null, 'warning' => 80]]]);
        self::assertSame(['health.typing' => ['warning' => 80]], $result['computed_metrics']);
    }

    #[Test]
    public function itRefusesAnUndeclaredSlotEvenWhenNullAndKeepsAnUnwrittenRule(): void
    {
        $this->assertRefusal(['rules' => [
            'complexity.ccn' => null,
            'code-smell.boolean-argument' => ['callable' => ['warning' => null]],
        ]], 'Unknown key "rules.code-smell.boolean-argument.callable" in configuration file "/project/qmx.yaml". Accepted keys: allowed-prefixes, flag-promoted-properties, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths.', ['rules', 'code-smell.boolean-argument', 'callable']);
        self::assertSame(['rules' => ['complexity.ccn' => null]], self::document(['rules' => ['complexity.ccn' => null]]));
    }

    /**
     * @param array<string, mixed> $written
     * @param non-empty-list<string> $path
     */
    private function assertRefusal(array $written, string $summary, array $path): void
    {
        try {
            self::document($written);
            self::fail('The authored input must be refused by its declaration.');
        } catch (\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal $refusal) {
            self::assertSame($summary, $refusal->summary());
            self::assertSame(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame($path, $refusal->position()?->segments);
            self::assertSame($path[\count($path) - 1], $refusal->position()->written);
        }
    }

    /**
     * @param array<string, mixed> $written
     *
     * @return array<string, mixed>
     */
    private static function document(array $written): array
    {
        $document = \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose(
            new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema([
                ...\Qualimetrix\Analysis\Configuration\ConfigurationRoot::cases(),
                ...\Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument::standaloneSections(),
            ]),
            [new \Qualimetrix\Analysis\Configuration\Document\AuthoredLayer(
                \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin::of(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::ConfigFile, '/project/qmx.yaml'),
                \Qualimetrix\Analysis\Configuration\Document\AuthoredNode::fromPlain($written),
            )],
        );
        return array_map(static fn(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): mixed => $value->plain(), $document->roots());
    }
}
