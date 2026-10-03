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
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
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

    /**
     * `enabled` is a key like any other: a file that writes a threshold for a
     * metric a preset switched off leaves it off, and must write
     * `enabled: true` to switch it back on.
     */
    #[Test]
    public function itKeepsAMetricAPresetSwitchedOffWhenTheFileWritesOnlyAThreshold(): void
    {
        $preset = self::preset(['computed_metrics' => ['health.complexity' => ['enabled' => false]]]);

        self::assertNull($this->configure([$preset, self::file(['computed_metrics' => ['health.complexity' => ['warning' => 95]]])])->find('health.complexity'));
        self::assertSame(
            95.0,
            $this->metric('health.complexity', $preset, self::file(['computed_metrics' => ['health.complexity' => ['enabled' => true, 'warning' => 95]]]))->warningThreshold,
        );
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

        self::assertSame($path, $refusal->position()?->segments);
        self::assertStringStartsWith('Unknown key', $refusal->summary());
        self::assertCount(1, $refusal->sources());
        self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
    }

    /** A preset's misspelling used to hide under any file that wrote the section. */
    #[Test]
    public function itRefusesAPresetsMisspelledKeyUnderAFileThatWritesTheSection(): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['health.complexity' => ['warnin' => 5]]]),
            self::file(['computed_metrics' => ['health.typing' => ['warning' => 60]]]),
        );

        self::assertCount(1, $refusal->sources());
        self::assertSame('preset "strict"', $refusal->sources()[0]->describe());
        self::assertSame(['computed_metrics', 'health.complexity', 'warnin'], $refusal->position()?->segments);
    }

    #[Test]
    public function itRefusesTheShorthandBesideItsTargetInOneLayer(): void
    {
        $refusal = $this->refusal(self::file(['computed_metrics' => ['computed.x' => [...self::USER_METRIC, 'threshold' => 5, 'warning' => 3]]]));

        self::assertStringContainsString('"threshold" is shorthand for "warning" and "error"', $refusal->summary());
    }

    /** A map where a list is due is named in the author's terms, not PHP's. */
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
        self::assertTrue($refusal->position()?->closed);
        self::assertContains('health.typing', $refusal->position()->accepted);
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
        self::assertSame(['computed_metrics', 'computed.x', 'formula'], $refusal->position()?->segments);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function provideUnknownComputedReferencesWithAnUnrelatedLaterWriting(): iterable
    {
        yield 'default formula' => [
            ['formula' => 'm["computed.missing"]', 'levels' => ['project']],
            ['formula'],
        ];
        yield 'namespace formula inherited by project' => [
            ['formulas' => ['namespace' => 'm["computed.missing"]'], 'levels' => ['project']],
            ['formulas', 'namespace'],
        ];
    }

    /**
     * @param array<string, mixed> $preset
     * @param list<string> $formulaPath
     */
    #[Test]
    #[DataProvider('provideUnknownComputedReferencesWithAnUnrelatedLaterWriting')]
    public function itAttributesAnUnknownComputedReferenceToItsFormulaWriter(array $preset, array $formulaPath): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['computed.x' => $preset]]),
            self::file(['computed_metrics' => ['computed.x' => ['description' => 'label']]]),
        );

        self::assertStringContainsString('references unknown metric "computed.missing"', $refusal->summary());
        self::assertSame(['preset "strict"'], self::described($refusal));
        self::assertSame(['computed_metrics', 'computed.x', ...$formulaPath], $refusal->position()?->segments);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string, list<string>}> */
    public static function provideSelectedBrokenFormulas(): iterable
    {
        yield 'project inherits namespace outside the reporting subset' => [
            ['levels' => ['project'], 'formulas' => ['namespace' => '1 +']],
            ['description' => 'label'], 'preset "strict"', ['formulas', 'namespace'],
        ];
        yield 'explicit project beats namespace' => [
            ['levels' => ['project'], 'formulas' => ['namespace' => '4', 'project' => '1 +']],
            ['description' => 'label'], 'preset "strict"', ['formulas', 'project'],
        ];
        yield 'specific below generic' => [
            ['levels' => ['project'], 'formulas' => ['project' => '1 +']],
            ['formula' => '4'], 'preset "strict"', ['formulas', 'project'],
        ];
        yield 'specific above generic' => [
            ['levels' => ['project'], 'formula' => '4'],
            ['formulas' => ['project' => '1 +']], 'configuration file "/p/qmx.yaml"', ['formulas', 'project'],
        ];
        yield 'generic below a namespace refinement' => [
            ['levels' => ['project'], 'formula' => '1 +'],
            ['formulas' => ['namespace' => '4']], 'preset "strict"', ['formula'],
        ];
        yield 'generic above a namespace refinement' => [
            ['levels' => ['project'], 'formulas' => ['namespace' => '4']],
            ['formula' => '1 +'], 'configuration file "/p/qmx.yaml"', ['formula'],
        ];
        yield 'namespace reporting subset' => [
            ['levels' => ['namespace'], 'formulas' => ['namespace' => '1 +']],
            ['description' => 'label'], 'preset "strict"', ['formulas', 'namespace'],
        ];
        yield 'class reporting subset' => [
            ['levels' => ['class'], 'formula' => '4'],
            ['formulas' => ['class' => '1 +']], 'configuration file "/p/qmx.yaml"', ['formulas', 'class'],
        ];
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<string, mixed> $file
     * @param list<string> $formulaPath
     */
    #[Test]
    #[DataProvider('provideSelectedBrokenFormulas')]
    public function itAttributesTheSelectedBrokenFormulaToItsExactWriter(array $preset, array $file, string $writer, array $formulaPath): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['computed.x' => $preset]]),
            self::file(['computed_metrics' => ['computed.x' => $file]]),
        );

        self::assertStringContainsString('Invalid formula syntax', $refusal->summary());
        self::assertSame([$writer], self::described($refusal));
        self::assertSame(['computed_metrics', 'computed.x', ...$formulaPath], $refusal->position()?->segments);
    }

    #[Test]
    public function itKeepsABuiltInProjectFormulaAndItsDefaultAuthorshipUnderANamespaceOverride(): void
    {
        $layers = [self::file(['computed_metrics' => [
            'health.complexity' => ['levels' => ['project'], 'formulas' => ['namespace' => '4']],
            'health.overall' => ['description' => 'label'],
        ]])];
        $metric = $this->metric('health.complexity', ...$layers);
        $defaults = ComputedMetricDefaults::getDefaults()['health.complexity'];
        $document = DocumentComposer::compose(new DocumentSchema([new ComputedMetricsSection()]), $layers);
        $section = $document->get(ComputedMetricsSection::KEY);
        self::assertInstanceOf(ResolvedMapInterface::class, $section);
        $authorship = new ComputedMetricAuthorship($section->entries());

        self::assertSame('4', $metric->getFormulaForLevel(SymbolLevel::Namespace_));
        self::assertSame($defaults->getFormulaForLevel(SymbolLevel::Project), $metric->getFormulaForLevel(SymbolLevel::Project));
        self::assertSame([], $authorship->writersOfFormula($metric, 'project'));
        self::assertSame(['the built-in defaults'], self::described($authorship->refuseFormula($metric, 'project', 'refused')));
        self::assertSame(['configuration file "/p/qmx.yaml"'], self::described($authorship->refuseFormula($metric, 'namespace', 'refused')));

        $overall = $this->metric('health.overall', ...$layers);
        $inheritedDefault = $authorship->refuseFormula($overall, 'project', 'refused');
        self::assertSame([], $authorship->writersOfFormula($overall, 'project'));
        self::assertSame(['the built-in defaults'], self::described($inheritedDefault));
        self::assertSame(['computed_metrics', 'health.overall', 'formulas', 'namespace'], $inheritedDefault->position()?->segments);
    }

    #[Test]
    public function itOrdersReferenceLevelConflictWritersByDocumentPrecedence(): void
    {
        $refusal = $this->refusal(
            self::preset(['computed_metrics' => ['computed.dependency' => ['levels' => ['class'], 'formula' => '4']]]),
            self::file(['computed_metrics' => ['health.overall' => ['levels' => ['project'], 'formula' => 'm["computed.dependency"]']]]),
        );

        self::assertStringContainsString('where it is not published', $refusal->summary());
        self::assertSame(['preset "strict"', 'configuration file "/p/qmx.yaml"'], self::described($refusal));
        self::assertSame(['computed_metrics', 'health.overall'], $refusal->position()?->segments);
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
        self::assertSame(['computed_metrics', 'computed.x', 'levels', '1'], $refusal->position()?->segments);
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

    /** A file's typo is named in the file's words, not the option's. */
    #[Test]
    public function itRefusesAFilesUnknownHealthExclusionInTheFilesWords(): void
    {
        $refusal = $this->refusal(self::file(['exclude_health' => ['typing', 'typng']]));

        self::assertStringStartsWith('Unknown health dimension "typng" in "exclude_health[1]" in configuration file "/p/qmx.yaml".', $refusal->summary());
        self::assertStringNotContainsString('--exclude-health', $refusal->summary());
        self::assertSame(['exclude_health', '1'], $refusal->position()?->segments);
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

        self::assertSame(['exclude_health', '0'], $refusal->position()?->segments);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, list<string>}> */
    public static function provideUnrenormalizableOverallFormulas(): iterable
    {
        $formula = 'min(m["health.complexity"] ?? 75, 100)';
        yield 'generic before exclusion' => [
            ['computed_metrics' => ['health.overall' => ['formula' => $formula]]],
            ['exclude_health' => ['typing']], ['exclude_health', '0'],
        ];
        yield 'exclusion before generic' => [
            ['exclude_health' => ['typing']],
            ['computed_metrics' => ['health.overall' => ['formula' => $formula]]],
            ['computed_metrics', 'health.overall', 'formula'],
        ];
        yield 'exclusion before namespace refinement' => [
            ['exclude_health' => ['typing']],
            ['computed_metrics' => ['health.overall' => ['levels' => ['project'], 'formulas' => ['namespace' => $formula]]]],
            ['computed_metrics', 'health.overall', 'formulas', 'namespace'],
        ];
        yield 'exclusion before project refinement' => [
            ['exclude_health' => ['typing']],
            ['computed_metrics' => ['health.overall' => ['levels' => ['project'], 'formulas' => ['project' => $formula]]]],
            ['computed_metrics', 'health.overall', 'formulas', 'project'],
        ];
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<string, mixed> $file
     * @param list<string> $position
     */
    #[Test]
    #[DataProvider('provideUnrenormalizableOverallFormulas')]
    public function itNamesEveryLayerBehindAnOverallFormulaThatCannotBeRenormalized(array $preset, array $file, array $position): void
    {
        $refusal = $this->refusal(
            self::preset($preset),
            self::file($file),
        );

        self::assertStringContainsString('Cannot auto-renormalize "health.overall"', $refusal->summary());
        self::assertSame(['preset "strict"', 'configuration file "/p/qmx.yaml"'], self::described($refusal));
        self::assertSame($position, $refusal->position()?->segments);
    }

    /** A section the pipeline carries unread would otherwise be read as "nothing written". */
    #[Test]
    public function itRefusesToReadASectionTheDocumentCarriedUnread(): void
    {
        $unread = new readonly class implements DocumentSectionSchemaInterface {
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration(ComputedMetricsSection::KEY, NodeSchema::opaque());
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
