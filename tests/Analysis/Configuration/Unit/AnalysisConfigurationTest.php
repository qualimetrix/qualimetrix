<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;
use ReflectionClass;

#[CoversClass(ConfigurationDocument::class)]
final class AnalysisConfigurationTest extends TestCase
{
    #[Test]
    public function itExposesAuthoredTypedValuesWithoutAParallelRawTransport(): void
    {
        $sources = [
            ['source' => 'strict', 'values' => ['rules' => ['size.loc' => ['warning' => 1000]]]],
            ['source' => 'qmx.yaml', 'values' => ['rules' => ['size.loc' => ['error' => 2000]], 'only_rules' => ['size.loc'], 'disabled_rules' => ['security']]],
        ];
        $schema = new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema([...\Qualimetrix\Analysis\Configuration\ConfigurationRoot::cases(), ...\Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument::standaloneSections()]);
        $empty = \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, []);
        $unwritten = new ConfigurationDocument($sources, AbsolutePath::fromString('/project'), $empty);
        self::assertSame([], $unwritten->resolved()->roots());
        $original = [
            new \Qualimetrix\Analysis\Configuration\Document\AuthoredLayer(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin::of(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::Preset, 'strict'), \Qualimetrix\Analysis\Configuration\Document\AuthoredNode::fromPlain($sources[0]['values'])),
            new \Qualimetrix\Analysis\Configuration\Document\AuthoredLayer(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin::of(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::ConfigFile, 'qmx.yaml'), \Qualimetrix\Analysis\Configuration\Document\AuthoredNode::fromPlain($sources[1]['values'])),
        ];
        try {
            \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, $original);
            self::fail('The original unknown producer must not be enrolled.');
        } catch (\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal $refusal) {
            self::assertSame('Rule option owner "size.loc" does not match any registered producer rule.', $refusal->summary());
            self::assertSame('strict', $refusal->sources()[0]->locator());
            self::assertSame(['rules', 'size.loc'], $refusal->position()?->segments);
            self::assertSame('size.loc', $refusal->position()->written);
        }
        $resolved = \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, [
            new \Qualimetrix\Analysis\Configuration\Document\AuthoredLayer($original[0]->origin, \Qualimetrix\Analysis\Configuration\Document\AuthoredNode::fromPlain(['rules' => ['size.method-count' => ['warning' => 1000]]])),
            new \Qualimetrix\Analysis\Configuration\Document\AuthoredLayer($original[1]->origin, \Qualimetrix\Analysis\Configuration\Document\AuthoredNode::fromPlain(['rules' => ['size.method-count' => ['error' => 2000]], 'only_rules' => ['size.method-count'], 'disabled_rules' => ['security']])),
        ]);
        $document = new ConfigurationDocument($sources, AbsolutePath::fromString('/project'), $resolved);
        $warning = $document->resolved()->get('rules', 'size.method-count', 'warning');
        $error = $document->resolved()->get('rules', 'size.method-count', 'error');
        self::assertNotNull($warning);
        self::assertNotNull($error);
        self::assertSame(1000, $warning->plain());
        self::assertSame(2000, $error->plain());
        self::assertSame(['strict', 'qmx.yaml'], [$warning->contributors()[0]->origin->locator(), $error->contributors()[0]->origin->locator()]);
        self::assertSame([0, 1], [$warning->contributors()[0]->layerIndex, $error->contributors()[0]->layerIndex]);
        self::assertSame(['size.method-count'], $document->resolved()->get('only_rules')?->plain());
        $disabled = $document->resolved()->get('disabled_rules');
        self::assertInstanceOf(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface::class, $disabled);
        self::assertSame(['security'], $disabled->plain());
        self::assertSame([['security']], array_map(static fn(array $write): mixed => $write['value'], $disabled->writes()));
    }

    #[Test]
    public function itDoesNotExposeAMergedGenericConfigurationBag(): void
    {
        $methodNames = array_map(
            static fn($method): string => $method->getName(),
            (new ReflectionClass(ConfigurationDocument::class))->getMethods(),
        );

        self::assertNotContains('all', $methodNames);
        self::assertNotContains('sources', $methodNames);
        self::assertNotContains('contributions', $methodNames);
        self::assertNotContains('ruleContributions', $methodNames);
        self::assertNotContains('onlyRuleContributions', $methodNames);
        self::assertNotContains('disabledRuleContributions', $methodNames);
    }

    #[Test]
    public function itKeepsComposerDiscoveryFactsSeparateFromAuthoredValues(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'composer.json', 'values' => [
                'discovered_autoload_paths' => ['src'],
                'discovered_autoload_dev_paths' => ['tests'],
            ]],
        ], AbsolutePath::fromString('/project'));

        self::assertSame(['src'], $document->discoveredProductionAutoloadTargets());
        self::assertSame(['tests'], $document->discoveredDevelopmentAutoloadTargets());
    }

    #[Test]
    public function itReturnsTheInvocationWorkingDirectory(): void
    {
        self::assertEquals(
            AbsolutePath::fromString('/invocation'),
            (new ConfigurationDocument([], AbsolutePath::fromString('/invocation')))->workingDirectory(),
        );
    }
}
