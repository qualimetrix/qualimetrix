<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricAuthorship;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * `computed_metrics` and `exclude_health` as the configuration layers write
 * them: each layer read on its own, then merged metric by metric and key by
 * key, and every refusal naming the layer that wrote what it refuses.
 */
#[CoversClass(ComputedMetricsSection::class)]
#[CoversClass(ExcludeHealthSection::class)]
#[CoversClass(ComputedMetricEntryKeys::class)]
#[CoversClass(ComputedMetricsConfigResolver::class)]
#[CoversClass(ComputedMetricOverrideReader::class)]
#[CoversClass(ComputedMetricAuthorship::class)]
final class ComputedMetricLayeringTest extends TestCase
{
    private const array USER_METRIC = ['formula' => '4', 'levels' => ['project']];

    #[Test]
    public function itExpandsTheThresholdShorthandInTheLayerThatWroteIt(): void
    {
        $metric = $this->metric('computed.x', self::preset(['computed_metrics' => ['computed.x' => [...self::USER_METRIC, 'threshold' => 5]]]), self::file(['computed_metrics' => ['computed.x' => ['warning' => 3]]]));

        self::assertSame('4', $metric->getFormulaForLevel(SymbolLevel::Project));
        self::assertSame(3.0, $metric->warningThreshold);
        self::assertSame(5.0, $metric->errorThreshold);
    }

    #[Test]
    public function itKeepsAPresetErrorWhenTheFileWritesOnlyWarning(): void
    {
        $metric = $this->metric('health.complexity', self::preset(['computed_metrics' => ['health.complexity' => ['warning' => 60, 'error' => 30]]]), self::file(['computed_metrics' => ['health.complexity' => ['warning' => 70]]]));

        self::assertSame(70.0, $metric->warningThreshold);
        self::assertSame(30.0, $metric->errorThreshold);
    }

    #[Test]
    public function itKeepsAPresetMetricTheFileDoesNotName(): void
    {
        $analysis = $this->configure([
            self::preset(['computed_metrics' => ['computed.x' => self::USER_METRIC]]),
            self::file(['computed_metrics' => ['health.complexity' => ['warning' => 60]]]),
        ]);

        self::assertNotNull($analysis->find('computed.x'));
        self::assertSame(60.0, $analysis->find('health.complexity')?->warningThreshold);
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideWritingsThatChangeNothing(): iterable
    {
        yield 'an empty section' => [['computed_metrics' => []]];
        yield 'a null section' => [['computed_metrics' => null]];
        yield 'a null metric' => [['computed_metrics' => ['computed.x' => null]]];
        yield 'an empty metric' => [['computed_metrics' => ['computed.x' => []]]];
        yield 'null keys' => [['computed_metrics' => ['computed.x' => ['warning' => null, 'enabled' => null, 'formulas' => ['class' => null]]]]];
    }

    /** @param array<string, mixed> $file */
    #[Test]
    #[DataProvider('provideWritingsThatChangeNothing')]
    public function itLeavesTheLowerLayerStandingUnderAWritingThatChangesNothing(array $file): void
    {
        $metric = $this->metric('computed.x', self::preset(['computed_metrics' => ['computed.x' => [...self::USER_METRIC, 'warning' => 3]]]), self::file($file));

        self::assertSame('4', $metric->getFormulaForLevel(SymbolLevel::Project));
        self::assertSame(3.0, $metric->warningThreshold);
    }

    #[Test]
    public function itRemovesAPresetMetricByEnabledFalse(): void
    {
        $analysis = $this->configure([
            self::preset(['computed_metrics' => ['computed.x' => self::USER_METRIC]]),
            self::file(['computed_metrics' => ['computed.x' => ['enabled' => false]]]),
        ]);

        self::assertNull($analysis->find('computed.x'));
    }

    #[Test]
    public function itRefinesOneLevelOverAFormulaFromBelow(): void
    {
        $metric = $this->metric(
            'computed.x',
            self::preset(['computed_metrics' => ['computed.x' => ['formula' => '4', 'levels' => ['class', 'namespace']]]]),
            self::file(['computed_metrics' => ['computed.x' => ['formulas' => ['class' => '1']]]]),
        );

        self::assertSame('1', $metric->getFormulaForLevel(SymbolLevel::Class_));
        self::assertSame('4', $metric->getFormulaForLevel(SymbolLevel::Namespace_));
    }

    /** A refinement is not undone by a default written above it: the two live side by side. */
    #[Test]
    public function itKeepsALowerRefinementUnderAFormulaFromAbove(): void
    {
        $metric = $this->metric(
            'computed.x',
            self::preset(['computed_metrics' => ['computed.x' => ['formulas' => ['class' => '1'], 'levels' => ['class', 'namespace']]]]),
            self::file(['computed_metrics' => ['computed.x' => ['formula' => '4']]]),
        );

        self::assertSame('1', $metric->getFormulaForLevel(SymbolLevel::Class_));
        self::assertSame('4', $metric->getFormulaForLevel(SymbolLevel::Namespace_));
    }

    #[Test]
    public function itReplacesTheLevelListWhole(): void
    {
        $metric = $this->metric(
            'computed.x',
            self::preset(['computed_metrics' => ['computed.x' => ['formula' => '4', 'levels' => ['class', 'namespace']]]]),
            self::file(['computed_metrics' => ['computed.x' => ['levels' => ['project']]]]),
        );

        self::assertSame([SymbolLevel::Project], $metric->levels);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function provideMisspelledKeysWrittenNull(): iterable
    {
        yield 'entry key' => [['health.complexity' => ['warnin' => null]], ['computed_metrics', 'health.complexity', 'warnin']];
        yield 'formulas key' => [['health.complexity' => ['formulas' => ['clas' => null]]], ['computed_metrics', 'health.complexity', 'formulas', 'clas']];
        yield 'formulas key naming a level not reported at' => [['health.complexity' => ['formulas' => ['callable' => null]]], ['computed_metrics', 'health.complexity', 'formulas', 'callable']];
    }

    /**
     * `~` means "not written" only for a key the section knows; a misspelled
     * one is refused whatever its value, at the spelling the author wrote.
     *
     * @param array<string, mixed> $section
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideMisspelledKeysWrittenNull')]
    public function itRefusesAMisspelledKeyEvenWhenItsValueIsNull(array $section, array $path): void
    {
        $refusal = $this->refusal(self::file(['computed_metrics' => $section]));

        self::assertSame($path, $refusal->position()?->segments());
        self::assertStringStartsWith('Unknown key', $refusal->summary());
        self::assertSame(ConfigurationSource::ConfigFile, $refusal->origin()->source());
    }

    /** A preset's misspelling used to hide under any file that wrote the section. */
    #[Test]
    public function itRefusesAPresetsMisspelledKeyUnderAFileThatWritesTheSection(): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['health.complexity' => ['warnin' => 5]]]),
            self::file(['computed_metrics' => ['health.typing' => ['warning' => 60]]]),
        );

        self::assertSame('preset "strict"', $refusal->origin()->describe());
        self::assertSame(['computed_metrics', 'health.complexity', 'warnin'], $refusal->position()?->segments());
    }

    #[Test]
    public function itRefusesTheShorthandBesideItsTargetInOneLayer(): void
    {
        $refusal = $this->refusal(self::file(['computed_metrics' => ['computed.x' => [...self::USER_METRIC, 'threshold' => 5, 'warning' => 3]]]));

        self::assertStringContainsString('"threshold" is shorthand for "warning" and "error"', $refusal->summary());
    }

    /** X-07: the written shape is named in the author's terms, not PHP's. */
    #[Test]
    public function itNamesAMapWrittenAsLevelsAMap(): void
    {
        $refusal = $this->refusal(self::file(['computed_metrics' => ['computed.x' => ['formula' => '4', 'levels' => ['a' => 'b']]]]));

        self::assertStringEndsWith('must be a list, got a map.', $refusal->summary());
        self::assertStringNotContainsString('array', $refusal->summary());
    }

    #[Test]
    public function itRefusesAnUnknownHealthNameInTheLayerThatWroteItFirst(): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['health.typng' => ['warning' => 60]]]),
            self::file(['computed_metrics' => ['health.typng' => ['error' => 30]]]),
        );

        self::assertStringContainsString('"health.typng" is not a known "health.*" dimension', $refusal->summary());
        self::assertSame(['preset "strict"'], self::described($refusal));
        self::assertTrue($refusal->position()?->isClosed());
        self::assertContains('health.typing', $refusal->position()->accepted());
    }

    #[Test]
    public function itAttributesABrokenFormulaToTheLayerThatWroteIt(): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['computed.x' => ['formula' => '4 +', 'levels' => ['project']]]]),
            self::file(['computed_metrics' => ['computed.x' => ['warning' => 3]]]),
        );

        self::assertStringContainsString('Invalid formula syntax', $refusal->summary());
        self::assertSame(['preset "strict"'], self::described($refusal));
        self::assertSame(['computed_metrics', 'computed.x', 'formula'], $refusal->position()?->segments());
    }

    #[Test]
    public function itNamesEveryLayerOfACycle(): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['computed.a' => ['formula' => 'm["computed.b"]', 'levels' => ['project']]]]),
            self::file(['computed_metrics' => ['computed.b' => ['formula' => 'm["computed.a"]', 'levels' => ['project']]]]),
        );

        self::assertStringContainsString('Circular dependency', $refusal->summary());
        self::assertSame(['preset "strict"', 'configuration file "/p/qmx.yaml"'], self::described($refusal));
    }

    #[Test]
    public function itAttributesALevelWordToTheLayerThatWroteTheList(): void
    {
        $refusal = $this->refusal(self::file(['computed_metrics' => ['computed.x' => ['formula' => '4', 'levels' => ['project', 'callable']]]]));

        self::assertStringContainsString('"callable" is not supported', $refusal->summary());
        self::assertSame(['computed_metrics', 'computed.x', 'levels', '1'], $refusal->position()?->segments());
    }

    #[Test]
    public function itAccumulatesHealthExclusionsAcrossEveryLayerInOrder(): void
    {
        $recorder = new RecordingHealthFormulaExcluder();

        $this->configure([
            self::preset(['exclude_health' => ['typing', 'complexity']]),
            self::file(['exclude_health' => ['typing', 'cohesion']]),
            self::cli(['exclude_health' => ['coupling']]),
        ], $recorder);

        self::assertSame(['health.typing', 'health.complexity', 'health.cohesion', 'health.coupling'], $recorder->excluded);
    }

    /** M-26: a file's typo is named in the file's words, not the option's. */
    #[Test]
    public function itRefusesAFilesUnknownHealthExclusionInTheFilesWords(): void
    {
        $refusal = $this->refusal(self::file(['exclude_health' => ['typing', 'typng']]));

        self::assertStringStartsWith('Unknown health dimension "typng" in "exclude_health[1]" in configuration file "/p/qmx.yaml".', $refusal->summary());
        self::assertStringNotContainsString('--exclude-health', $refusal->summary());
        self::assertSame(['exclude_health', '1'], $refusal->position()?->segments());
    }

    #[Test]
    public function itRefusesAnOptionsUnknownHealthExclusionInTheOptionsWords(): void
    {
        $refusal = $this->refusal(self::cli(['exclude_health' => ['typng']]));

        self::assertStringStartsWith('Unknown health dimension "typng" in option --exclude-health.', $refusal->summary());
        self::assertNull($refusal->position());
    }

    #[Test]
    public function itRefusesANullHealthExclusionItemAtItsIndex(): void
    {
        $refusal = $this->refusal(self::file(['exclude_health' => [null]]));

        self::assertSame(['exclude_health', '0'], $refusal->position()?->segments());
    }

    #[Test]
    public function itNamesEveryLayerBehindAnOverallFormulaThatCannotBeRenormalized(): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['health.overall' => ['formula' => 'min(m["health.complexity"] ?? 75, 100)']]]),
            self::file(['exclude_health' => ['typing']]),
        );

        self::assertStringContainsString('Cannot auto-renormalize "health.overall"', $refusal->summary());
        self::assertSame(['preset "strict"', 'configuration file "/p/qmx.yaml"'], self::described($refusal));
    }

    /** A section the pipeline carries unread would otherwise be read as "nothing written". */
    #[Test]
    public function itRefusesToReadASectionTheDocumentCarriedUnread(): void
    {
        $unread = new readonly class implements DocumentSectionSchemaInterface {
            public function key(): string
            {
                return ComputedMetricsSection::KEY;
            }

            public function schema(): NodeSchema
            {
                return NodeSchema::opaque();
            }
        };

        $analysis = new ComputedMetricAnalysis(new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()));

        self::expectException(LogicException::class);
        self::expectExceptionMessage(ComputedMetricsSection::class . ' must be registered');

        $analysis->resolve(new ConfigurationDocument([], AbsolutePath::fromString('/project'), DocumentComposer::compose(
            new DocumentSchema([$unread]),
            [self::file(['computed_metrics' => ['computed.x' => self::USER_METRIC]])],
        )));
    }

    private function metric(string $name, AuthoredLayer ...$layers): ComputedMetricDefinition
    {
        return $this->configure(array_values($layers))->find($name) ?? self::fail(\sprintf('"%s" is not defined.', $name));
    }

    private function refusal(AuthoredLayer ...$layers): ConfigurationRefusal
    {
        try {
            $this->configure(array_values($layers));
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('Expected a refusal.');
    }

    /** @param list<AuthoredLayer> $layers lowest precedence first */
    private function configure(array $layers, ?HealthFormulaExclusionInterface $excluder = null): ComputedMetricAnalysis
    {
        $analysis = new ComputedMetricAnalysis(new ComputedMetricsConfigResolver(
            new ComputedMetricFormulaValidator(),
            $excluder ?? new HealthFormulaExcluder(),
        ));

        $document = new ConfigurationDocument([], AbsolutePath::fromString('/project'), DocumentComposer::compose(
            new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]),
            $layers,
        ));

        $analysis->replace($analysis->resolve($document));

        return $analysis;
    }

    /** @return list<string> */
    private static function described(ConfigurationRefusal $refusal): array
    {
        return array_map(static fn(ConfigurationOrigin $origin): string => $origin->describe(), $refusal->sources());
    }

    /** @param array<string, mixed> $document */
    private static function preset(array $document): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict'), AuthoredNode::fromPlain($document));
    }

    /** @param array<string, mixed> $document */
    private static function file(array $document): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'), AuthoredNode::fromPlain($document));
    }

    /** @param array{exclude_health: list<string>} $values */
    private static function cli(array $values): AuthoredLayer
    {
        return new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine),
            AuthoredNode::mapping(['exclude_health' => AuthoredNode::fromPlain($values['exclude_health'], '--exclude-health')]),
            false,
        );
    }
}

final class RecordingHealthFormulaExcluder implements HealthFormulaExclusionInterface
{
    /** @var list<string> */
    public array $excluded = [];

    public function applyExcludeHealth(array $definitions, array $excludedDimensions, Closure $refuseOverall): array
    {
        $this->excluded = $excludedDimensions;

        return $definitions;
    }
}
