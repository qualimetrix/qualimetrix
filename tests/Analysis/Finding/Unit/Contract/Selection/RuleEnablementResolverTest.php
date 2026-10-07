<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract\Selection;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Finding\Contract\SelectionRecord;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(RuleEnablementResolver::class)]
final class RuleEnablementResolverTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function selectorRoots(): iterable
    {
        yield 'only' => ['only_rules', null];
        yield 'disabled' => ['disabled_rules', null];
        yield 'cli-only' => ['only_rules', '--only-rule'];
        yield 'cli-disabled' => ['disabled_rules', '--disable-rule'];
    }

    #[Test]
    #[DataProvider('selectorRoots')]
    public function itJudgesEveryLowerSelectorWriteWithItsOriginalPosition(string $root, ?string $option): void
    {
        if ($option !== null) {
            $execution = ResolvedOptionsFixture::execution(self::metadata());
            $schema = new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema([
                new \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection($execution, 'rules'),
                new \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection($execution, 'only_rules'),
                new \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection($execution, 'disabled_rules'),
            ]);
            $channels = ResolvedOptionsFixture::universe(self::metadata());
            $compose = static fn(array $selectors) => \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, [
                \Qualimetrix\Analysis\Configuration\Loader\CommandLineLayer::of(new \Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest(
                    AbsolutePath::fromString('/project'),
                    cliValues: [$root => $selectors],
                    cliOptionNames: [$root => $option],
                )),
            ]);
            $lawful = (new RuleEnablementResolver())->decide($compose(['complexity.alpha']), $channels);
            self::assertSame($root === 'only_rules', $lawful->decisionFor('complexity.alpha')->on);
            self::assertTrue($lawful->decisionFor('complexity.beta')->on);
            self::assertSame($root === 'only_rules' ? ['complexity.alpha'] : null, $lawful->filter()?->selectors);
            try {
                (new RuleEnablementResolver())->decide($compose(['complexity.alpha', 'nosuch.channel']), $channels);
                self::fail('The unknown CLI selector was accepted.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertSame('Rule selector "nosuch.channel" does not match any registered producer or channel.', $refusal->summary());
                self::assertSame(ConfigurationSource::CommandLine, $refusal->sources()[0]->source());
                self::assertSame($option, $refusal->sources()[0]->locator());
                self::assertNull($refusal->position());
            }
            return;
        }
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => [$root => ['complexity.alpha', 'nosuch.channel']]],
            ['source' => 'preset', 'values' => [$root => ['complexity.beta']]],
        ], AbsolutePath::fromString('/project'), self::metadata());
        try {
            (new RuleEnablementResolver())->decide($document->resolved(), ResolvedOptionsFixture::universe(self::metadata()));
            self::fail('The unknown lower selector was ignored.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Rule selector "nosuch.channel" does not match any registered producer or channel.', $refusal->summary());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertNotNull($refusal->position());
            self::assertSame([$root, '1'], $refusal->position()->segments);
        }
    }

    #[Test]
    public function itJudgesNamespaceChannelKeysBeforeBuildingOptions(): void
    {
        $metadata = [new RuleMetadata('demo.many', CodeSmellOptions::class, '', [], false)];
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['demo.many' => ['suppress_namespace_channels' => ['demo.class' => [['exact' => 'App']]]]]]],
            ['source' => 'preset', 'values' => ['rules' => ['demo.many' => ['suppress_namespace_channels' => ['demo.namespace' => [['exact' => 'App']]]]]]],
        ], AbsolutePath::fromString('/project'), $metadata);
        $channels = new ChannelUniverse(
            ['demo.class' => ChannelDeclaration::occurrence(SymbolLevel::Class_), 'demo.namespace' => ChannelDeclaration::occurrence(SymbolLevel::Namespace_)],
            ['demo.many' => ['demo.class', 'demo.namespace']],
            ['demo.many' => false],
            new ResolvedComputedMetricDefinitions([]),
            ...self::unusedReachPorts(),
        );
        try {
            (new RuleEnablementResolver())->decide($document->resolved(), $channels);
            self::fail('The class-only namespace suppression key was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('does not report at level "namespace"', $refusal->summary());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertNotNull($refusal->position());
            self::assertSame(['rules', 'demo.many', 'suppress_namespace_channels', 'demo.class'], $refusal->position()->segments);
        }
    }

    #[Test]
    public function itCarriesTheNarrowedGroupDiagnosticWithEveryIndexedWriter(): void
    {
        $metadata = [new RuleMetadata('code-smell.eval', CodeSmellOptions::class, '', [], false)];
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['only_rules' => ['code-smell.*']]],
            ['source' => 'preset', 'values' => ['disabled_rules' => ['code-smell.*']]],
        ], AbsolutePath::fromString('/project'), $metadata);
        $channels = ResolvedOptionsFixture::universe($metadata);
        $stated = (new RuleEnablementResolver())->decide($document->resolved(), $channels);
        self::assertCount(1, $stated->diagnostics());
        $diagnostic = $stated->diagnostics()[0];
        self::assertSame('The group "code-smell.*" no longer includes "design.god-class" or "design.data-class". Name those producers explicitly if they should remain selected.', $diagnostic->message);
        self::assertSame(['only_rules[0]', 'disabled_rules[0]'], array_map(static fn($writer): string => $writer->displayPath(), $diagnostic->sources));
        self::assertSame([0, 1], array_map(static fn($writer): int => $writer->layerIndex, $diagnostic->sources));
        $configuration = FindingConfiguration::fromDocument($document)->withDiagnostics($stated->diagnostics());
        $ready = ResolvedOptionsFixture::ready(ResolvedOptionsFixture::authoredConfiguration([], $metadata), $metadata);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions::class, $ready->resolvedOptions);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RuleEnablement::class, $ready->enablement);
        foreach ([$configuration->withChannelUniverse($channels), $configuration->withResolvedOptions($ready->resolvedOptions), $configuration->withEnablement($ready->enablement), $configuration->withDiagnostics($stated->diagnostics())] as $copy) {
            self::assertSame([$diagnostic], $copy->diagnostics);
        }
        $exact = ResolvedOptionsFixture::document([['source' => 'config', 'values' => ['only_rules' => ['code-smell.eval']]]], AbsolutePath::fromString('/project'), $metadata);
        self::assertSame([], (new RuleEnablementResolver())->decide($exact->resolved(), $channels)->diagnostics());
    }

    #[Test]
    public function itLetsTheHigherLayerOutrankAProblemFromTheMoreSpecificLowerLayer(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['complexity.alpha' => ['enabled' => true]]]],
            ['source' => 'preset', 'values' => ['disabled_rules' => ['complexity.*']]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertFalse($enablement->runs('complexity.alpha'));
        self::assertFalse($enablement->runs('complexity.beta'));
        self::assertSame('disabled_rules[0]: complexity.*', $enablement->decisionFor('complexity.alpha')->statement);
        self::assertSame(1, $enablement->decisionFor('complexity.alpha')->rank());
    }

    #[Test]
    public function itUsesTheMoreSpecificStatementInsideOneLayer(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => [
                'rules' => ['complexity.alpha' => ['enabled' => true]],
                'disabled_rules' => ['complexity.*'],
            ]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertTrue($enablement->runs('complexity.alpha'));
        self::assertFalse($enablement->runs('complexity.beta'));
        self::assertSame('rules.complexity.alpha.enabled: true', $enablement->decisionFor('complexity.alpha')->statement);
        self::assertSame(0, $enablement->decisionFor('complexity.alpha')->rank());
    }

    #[Test]
    public function itClearsTheOnlyFilterWhenAHighLayerWritesAnEmptyList(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['only_rules' => ['complexity.alpha']]],
            ['source' => 'preset', 'values' => ['only_rules' => []]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertNull($enablement->filter());
        self::assertTrue($enablement->runs('complexity.alpha'));
        self::assertTrue($enablement->runs('complexity.beta'));
    }

    #[Test]
    public function itLetsAnUpperDisableNarrowAnEarlierFilterWhileAnotherCellLives(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['only_rules' => ['complexity.alpha', 'complexity.beta']]],
            ['source' => 'preset', 'values' => ['disabled_rules' => ['complexity.alpha']]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertFalse($enablement->runs('complexity.alpha'));
        self::assertTrue($enablement->runs('complexity.beta'));
        self::assertSame(['complexity.alpha', 'complexity.beta'], $enablement->filter()?->selectors);
        self::assertSame('disabled_rules[0]: complexity.alpha', $enablement->decisionFor('complexity.alpha')->statement);
        self::assertSame(1, $enablement->decisionFor('complexity.alpha')->rank());
    }

    #[Test]
    public function itAttributesFinalModeActivityToTheUpperWrittenLayer(): void
    {
        $metadata = [new RuleMetadata('architecture.unassigned-class', UnassignedClassOptions::class, 'Unassigned', [], false)];
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['architecture.unassigned-class' => ['mode' => 'warn']]]],
            ['source' => 'preset', 'values' => ['rules' => ['architecture.unassigned-class' => ['mode' => 'ignore']]]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $decision = $ready->enablement?->decisionFor('architecture.unassigned-class');
        self::assertNotNull($decision);
        self::assertTrue($decision->on);
        self::assertFalse($decision->live());
        self::assertSame('rules.architecture.unassigned-class.mode: ignore', $decision->activity->written);
        self::assertSame(1, $decision->activity->rank());
        $writer = $decision->activity->decidedBy;
        self::assertNotNull($writer);
        self::assertSame('rules.architecture.unassigned-class.mode', $writer->displayPath());
        self::assertSame(ConfigurationSource::Preset, $writer->origin->source());
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        $records = $enablement->notRun();
        self::assertCount(1, $records);
        self::assertSame(
            [['disabled', 'rules.architecture.unassigned-class.mode: ignore', 'preset "fixture"']],
            array_map(static fn(SelectionRecord $record): array => [$record->reason, $record->statement, $record->layer], $records),
        );
    }

    #[Test]
    public function itNamesTheWinningOnlyFilterForANotRunProducer(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['only_rules' => ['complexity.alpha']]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        $records = $enablement->notRun();
        self::assertCount(1, $records);
        self::assertSame(
            [['complexity.beta', 'filtered', 'only_rules: [complexity.alpha]', 'configuration file "/project/qmx.yaml"']],
            array_map(static fn(SelectionRecord $record): array => [$record->producer, $record->reason, $record->statement, $record->layer], $records),
        );
    }

    /** @return list<RuleMetadata> */
    private static function metadata(): array
    {
        return [
            new RuleMetadata('complexity.alpha', CodeSmellOptions::class, 'Alpha', [], false),
            new RuleMetadata('complexity.beta', CodeSmellOptions::class, 'Beta', [], false),
        ];
    }

    /** @return array{\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface} */
    private static function unusedReachPorts(): array
    {
        return [
            new class implements \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface {
                public function metricReach(string $metricKey): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach
                {
                    throw new LogicException('This fixture does not query measured-metric reach.');
                }
            },
            new class implements \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface {
                public function reachAt(
                    string $metricName,
                    \Qualimetrix\Core\Symbol\SymbolLevel $level,
                    \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface $definitions,
                ): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach {
                    throw new LogicException('This fixture does not query computed-metric reach.');
                }
            },
        ];
    }
}
