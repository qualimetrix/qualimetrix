<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Symbol\SymbolLevel;
use RuntimeException;

#[CoversClass(ComputedMetricsConfigResolver::class)]
#[CoversClass(ComputedMetricFormulaValidator::class)]
final class ComputedMetricsConfigResolverTest extends TestCase
{
    private ComputedMetricsConfigResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ComputedMetricsConfigResolver(
            new ComputedMetricFormulaValidator(),
            new HealthFormulaExcluder(),
        );
    }

    #[Test]
    public function itKeepsTheNativeListQueryAsAProjectionOfSourcedResolution(): void
    {
        $document = DocumentComposer::compose(new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]), []);
        $list = $this->resolver->resolve($document);
        $snapshot = $this->resolver->resolveWithSources($document);
        self::assertCount(6, $list);
        self::assertEquals($snapshot->all(), $list);
        $cohesion = null;
        foreach ($list as $definition) {
            if ($definition->name === 'health.cohesion') {
                $cohesion = $definition;
            }
        }
        self::assertNotNull($cohesion, 'Native foreach consumers must actually reach the definitions.');
        self::assertTrue($cohesion->isBuiltinFormulaForLevel(SymbolLevel::Project));
        self::assertFalse($cohesion->getApplicabilityForLevel(SymbolLevel::Project)->appliesTo([]));
    }

    #[Test]
    public function itResolveWithEmptyConfigReturns6Defaults(): void
    {
        $result = $this->resolve([]);

        self::assertCount(6, $result);

        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertContains('health.complexity', $names);
        self::assertContains('health.cohesion', $names);
        self::assertContains('health.coupling', $names);
        self::assertContains('health.typing', $names);
        self::assertContains('health.maintainability', $names);
        self::assertContains('health.overall', $names);
    }

    #[Test]
    public function itOverridesHealthThresholdOnly(): void
    {
        $result = $this->resolve([
            'health.complexity' => [
                'warning' => 60.0,
                'error' => 30.0,
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        self::assertSame(60.0, $complexity->warningThreshold);
        self::assertSame(30.0, $complexity->errorThreshold);
        // Other fields should be inherited from defaults
        self::assertTrue($complexity->inverted);
        self::assertSame('Complexity health score (0-100, higher is better)', $complexity->description);
    }

    #[Test]
    public function itOverridesHealthFormulaSingular(): void
    {
        $result = $this->resolve([
            'health.complexity' => [
                'formula' => '100 - m["complexity.ccn.avg"] * 10',
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        // Singular formula applies to all levels
        self::assertSame('100 - m["complexity.ccn.avg"] * 10', $complexity->getFormulaForLevel(SymbolLevel::Class_));
        self::assertSame('100 - m["complexity.ccn.avg"] * 10', $complexity->getFormulaForLevel(SymbolLevel::Namespace_));
        self::assertSame('100 - m["complexity.ccn.avg"] * 10', $complexity->getFormulaForLevel(SymbolLevel::Project));
    }

    #[Test]
    public function itOverridesHealthFormulasPerLevel(): void
    {
        $result = $this->resolve([
            'health.complexity' => [
                'formulas' => [
                    'class' => '100 - m["complexity.ccn"] * 5',
                ],
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        // Only class formula overridden
        self::assertSame('100 - m["complexity.ccn"] * 5', $complexity->getFormulaForLevel(SymbolLevel::Class_));
        // Namespace keeps default formula (uses per-method average via ccn__sum / symbolMethodCount)
        self::assertStringContainsString('m["complexity.ccn.sum"]', (string) $complexity->getFormulaForLevel(SymbolLevel::Namespace_));
    }

    #[Test]
    public function itDisablesHealthMetricAndRebuildsOverall(): void
    {
        // Disabling a single health.* dimension via `enabled: false` must NOT break
        // health.overall — instead, weights are renormalized exactly like exclude_health.
        $result = $this->resolve([
            'health.typing' => [
                'enabled' => false,
            ],
        ]);

        // 6 defaults - 1 disabled = 5 (health.overall stays)
        self::assertCount(5, $result);
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.typing', $names);
        self::assertContains('health.overall', $names);

        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);
        $classFormula = $overall->formulas['class'] ?? '';
        $expression = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression();
        self::assertSame([
            'health.complexity' => ['weight' => 0.35],
            'health.cohesion' => ['weight' => 0.25],
            'health.coupling' => ['weight' => 0.25],
        ], \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\WeightedHealthFormula::termsOf($expression, $classFormula));
        [$missing, $value] = $expression->evaluateOn($classFormula, new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\MetricLookup([
            'health.complexity' => 100, 'health.cohesion' => 100, 'health.coupling' => 100,
        ]));
        self::assertSame([], $missing);
        self::assertEqualsWithDelta(100, $value, 0.001);
    }

    #[Test]
    public function itDisablesOverallDimensionDirectly(): void
    {
        // Disabling health.overall directly is also supported — it has no dependents,
        // so sub-dimensions are untouched.
        $result = $this->resolve([
            'health.overall' => [
                'enabled' => false,
            ],
        ]);

        self::assertCount(5, $result);
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.overall', $names);
        self::assertContains('health.typing', $names);
    }

    #[Test]
    public function itCombinesEnabledFalseAndExcludeHealth(): void
    {
        // Combining `enabled: false` on one dimension with `exclude_health` on another
        // removes both; health.overall is rebuilt with the remaining weights.
        $result = $this->resolve(
            [
                'health.typing' => ['enabled' => false],
            ],
            ['maintainability'],
        );

        self::assertCount(4, $result); // typing + maintainability gone; overall stays
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.typing', $names);
        self::assertNotContains('health.maintainability', $names);
        self::assertContains('health.overall', $names);

        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);
        self::assertStringNotContainsString('m["health.typing"]', $overall->formulas['namespace'] ?? '');
        self::assertStringNotContainsString('m["health.maintainability"]', $overall->formulas['namespace'] ?? '');
    }

    #[Test]
    public function itDisablesUserComputedMetric(): void
    {
        // Custom `computed.*` metrics disabled via `enabled: false` are simply removed —
        // no formula renormalization applies (no health.overall reference path).
        $result = $this->resolve([
            'computed.foo' => [
                'formula' => 'm["size.loc.avg"] * 2',
                'levels' => ['namespace'],
                'enabled' => false,
            ],
        ]);

        self::assertCount(6, $result); // defaults untouched, custom rejected
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('computed.foo', $names);
    }

    /**
     * A typo in a `health.*` name is refused the same way whether the entry
     * carries `enabled: false` or a formula — the two paths that used to
     * disagree ({@see itThrowsForReservedHealthPrefixOnNewMetric()} is the
     * other) collapse into one refusal, raised before either branch runs.
     */
    #[Test]
    public function itDisablingUnknownHealthDimensionThrowsTailoredError(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Computed metric name "health.typying" is not a known "health.*" dimension');
        self::expectExceptionMessage('health.overall');

        $this->resolve([
            'health.typying' => [
                'enabled' => false,
            ],
        ]);
    }

    /** The position is the name as the author wrote it, closed on the six names. */
    #[Test]
    public function itPositionsAnUnknownHealthDimensionAtTheWrittenName(): void
    {
        try {
            $this->resolve(['health.typying' => ['enabled' => false]]);
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'health.typying'], $position->segments);
            self::assertSame('health.typying', $position->written);
            self::assertTrue($position->closed);
            self::assertSame(
                ['health.cohesion', 'health.complexity', 'health.coupling', 'health.maintainability', 'health.overall', 'health.typing'],
                $position->accepted,
            );
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
        }
    }

    #[Test]
    public function itExcludeHealthAcceptsBothNameForms(): void
    {
        // Both bare ('typing') and fully-qualified ('health.typing') forms are
        // accepted in exclude_health, and dedupe across them.
        $result = $this->resolve([], ['typing', 'health.typing']);

        // 6 defaults - 1 excluded = 5
        self::assertCount(5, $result);
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.typing', $names);
    }

    #[Test]
    public function itThrowsForUnknownExcludeHealthArg(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown health dimension "nonexistent" in "exclude_health[0]" in configuration file "qmx.yaml".');

        $this->resolve([], ['nonexistent']);
    }

    #[Test]
    public function itOffersEveryExcludableDimensionButOverallForAnUnknownExclusion(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage(
            'Valid dimensions: health.complexity, health.cohesion, health.coupling, health.typing, health.maintainability.',
        );

        $this->resolve([], ['nonexistent']);
    }

    #[Test]
    public function itAttributesARefusalOfAnUntouchedDefinitionToTheDefaultsAtTheKeyItLacks(): void
    {
        $definition = new ComputedMetricDefinition('computed.x', [], 'x', [SymbolLevel::Class_]);

        try {
            (new ComputedMetricFormulaValidator())->validate([$definition]);
            self::fail('Expected a ConfigurationRefusal.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::Defaults, $refusal->sources()[0]->source());
            self::assertSame(['computed_metrics', 'computed.x', 'formulas', 'class'], $refusal->position()?->segments);
            self::assertSame('class', $refusal->position()->written);
        }
    }

    #[Test]
    public function itPositionsARefusalOfAMetricAbsentAtALevelAtTheMetricEntry(): void
    {
        try {
            ComputedMetricFormulaValidator::refuseMetricsAbsentAtLevel(
                new ComputedMetricDefinition(name: 'computed.x', formulas: ['class' => 'm["size.loc"]'], description: '', levels: [SymbolLevel::Class_]),
                ['size.loc'],
                SymbolLevel::Class_,
                'm["size.loc"]',
                (new \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis($this->resolver))->refuseFormula(...),
            );
        } catch (ConfigurationRefusal $refusal) {
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::Resolved, $refusal->sources()[0]->source());
            self::assertSame(['computed_metrics', 'computed.x'], $refusal->position()?->segments);
            self::assertSame('computed.x', $refusal->position()->written);
        }
    }

    #[Test]
    public function itCreatesNewComputedMetric(): void
    {
        $result = $this->resolve([
            'computed.my-score' => [
                'formula' => 'm["size.loc.avg"] * 2',
                'description' => 'My custom score',
                'levels' => ['class', 'namespace'],
                'inverted' => true,
                'warning' => 80.0,
                'error' => 40.0,
            ],
        ]);

        self::assertCount(7, $result); // 6 defaults + 1 custom
        $custom = $this->findByName($result, 'computed.my-score');
        self::assertNotNull($custom);
        self::assertSame('My custom score', $custom->description);
        self::assertTrue($custom->inverted);
        self::assertSame(80.0, $custom->warningThreshold);
        self::assertSame(40.0, $custom->errorThreshold);
        self::assertSame('m["size.loc.avg"] * 2', $custom->getFormulaForLevel(SymbolLevel::Class_));
        self::assertContains(SymbolLevel::Class_, $custom->levels);
        self::assertContains(SymbolLevel::Namespace_, $custom->levels);
        self::assertNotContains(SymbolLevel::Project, $custom->levels);
    }

    /**
     * A channel cannot declare one level twice, so a configuration that asks
     * for it has to be refused while the configuration is being read.
     * {@see ComputedMetricDefinition} owns the invariant; these two cases
     * pin that the resolver actually reaches it, from a fresh metric and
     * from an override of a built-in one.
     */
    #[Test]
    public function itRefusesARepeatedLevel(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('declares the same level more than once');

        $this->resolve([
            'computed.repeated' => [
                'formula' => 'm["size.loc.avg"]',
                'levels' => ['class', 'class'],
            ],
        ]);
    }

    #[Test]
    public function itRefusesARepeatedLevelInAnOverrideToo(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('declares the same level more than once');

        $this->resolve([
            'health.overall' => ['levels' => ['namespace', 'namespace']],
        ]);
    }

    /**
     * A level is a coordinate beside the channel name, addressed via
     * `channel:level`, never a word inside the name itself — the same
     * invariant enforced for statically declared channels
     * ({@see \Qualimetrix\Governance\Channel\ChannelLevelAssemblyTopologyTest}).
     * A user-defined metric name is the one place that invariant can still be
     * broken at runtime, since the user picks the name.
     */
    #[Test]
    public function itRefusesAUserDefinedMetricNameEndingInALevelWord(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('must not end in the level word "class"');

        $this->resolve([
            'computed.foo.class' => [
                'formula' => '1',
                'levels' => ['class'],
                'warning' => 0,
            ],
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLevelWordsEndingAName(): array
    {
        return [
            'callable' => ['computed.foo.callable'],
            'class' => ['computed.foo.class'],
            'file' => ['computed.foo.file'],
            'namespace' => ['computed.foo.namespace'],
            'project' => ['computed.foo.project'],
            'bare name, no prefix segment' => ['computed.namespace'],
        ];
    }

    #[Test]
    #[DataProvider('provideLevelWordsEndingAName')]
    public function itRefusesEveryLevelWordAsTheLastSegment(string $name): void
    {
        self::expectException(ConfigurationRefusal::class);

        $this->resolve([
            $name => [
                'formula' => '1',
                'levels' => ['namespace'],
            ],
        ]);
    }

    #[Test]
    public function itThrowsForInvalidPrefix(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('must be "health.<name>" or "computed.<name>"');

        $this->resolve([
            'custom.my_score' => [
                'formula' => 'loc * 2',
            ],
        ]);
    }

    #[Test]
    public function itThrowsForFormulaSyntaxError(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Invalid formula syntax');

        $this->resolve([
            'computed.bad' => [
                'formula' => 'loc +* 2',
                'levels' => ['namespace'],
            ],
        ]);
    }

    #[Test]
    public function itThrowsForCircularDependency(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Circular dependency');

        $this->resolve([
            'computed.a' => [
                'formula' => 'm["computed.b"] + 1',
                'levels' => ['namespace'],
            ],
            'computed.b' => [
                'formula' => 'm["computed.a"] + 1',
                'levels' => ['namespace'],
            ],
        ]);
    }

    #[Test]
    public function itThrowsForReferenceToNonExistentComputedMetric(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('references unknown metric "computed.nonexistent"');

        $this->resolve([
            'computed.ref' => [
                'formula' => 'm["computed.nonexistent"] + 1',
                'levels' => ['namespace'],
            ],
        ]);
    }

    #[Test]
    public function itAcceptsAFormulaReferencingAKnownCatalogKey(): void
    {
        $result = $this->resolve([
            'computed.valid' => [
                'formula' => 'm["size.loc"] * 2',
                'levels' => ['namespace'],
            ],
        ]);

        self::assertNotNull($this->findByName($result, 'computed.valid'));
    }

    #[Test]
    public function itThrowsForATypoedMetricKey(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('references unknown metric key "complexity.cnn"');

        $this->resolve([
            'computed.typo' => [
                'formula' => 'm["complexity.cnn"] + 1',
                'levels' => ['namespace'],
            ],
        ]);
    }

    #[Test]
    public function itDoesNotApplyTheCatalogCheckToHealthAndComputedCrossReferences(): void
    {
        // health.*/computed.* stay owned by validateComputedMetricReferences() above the
        // catalog check: a valid cross-reference must not be rejected as an unknown key.
        $result = $this->resolve([
            'computed.derived' => [
                'formula' => 'm["health.complexity"] + 1',
                'levels' => ['namespace'],
            ],
        ]);

        self::assertNotNull($this->findByName($result, 'computed.derived'));
    }

    /**
     * A computed metric exists only at the levels it declares. A formula at
     * another level reading it without `??` finds it on no symbol, so the
     * metric it defines would be published nowhere: the same mistake as a base
     * key no symbol at the level carries, refused by the same class.
     */
    #[Test]
    public function itRefusesAnUnguardedReadOfAComputedMetricNotPublishedAtTheFormulasLevel(): void
    {
        try {
            $this->resolve([
                'computed.cls-only' => ['formula' => 'm["size.method-count"] + 1', 'levels' => ['class']],
                'computed.proj-reads' => ['formula' => 'm["computed.cls-only"] + 1', 'levels' => ['project']],
            ]);
            self::fail('A cross-level unguarded read must be refused');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString(
                'Computed metric "computed.proj-reads" reads "computed.cls-only" at level "project",'
                . ' where it is not published (published at: class)',
                $refusal->getMessage(),
            );
            self::assertStringContainsString('Formula: m["computed.cls-only"] + 1', $refusal->getMessage());
        }
    }

    /**
     * `project` inherits the `namespace` formula; the inherited formula is read
     * at `project` and is refused there, not only where it is spelled.
     */
    #[Test]
    public function itRefusesAnInheritedProjectFormulaReadingAMetricPublishedOnlyAtNamespaceLevel(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('reads "computed.ns-only" at level "project", where it is not published (published at: namespace)');

        $this->resolve([
            'computed.ns-only' => ['formula' => 'm["size.loc"] + 1', 'levels' => ['namespace']],
            'computed.reader' => [
                'formulas' => ['namespace' => 'm["computed.ns-only"] * 2'],
                'levels' => ['namespace', 'project'],
            ],
        ]);
    }

    /** Per-level formulas are judged each at its own level. */
    #[Test]
    public function itRefusesOnlyTheLevelWhoseOwnFormulaReadsAnUnpublishedMetric(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('reads "computed.cls-only" at level "namespace"');

        $this->resolve([
            'computed.cls-only' => ['formula' => 'm["size.method-count"] + 1', 'levels' => ['class']],
            'computed.reader' => [
                'formulas' => [
                    'class' => 'm["computed.cls-only"] * 2',
                    'namespace' => 'm["computed.cls-only"] * 3',
                    'project' => 'm["computed.cls-only"] ?? 0',
                ],
                'levels' => ['class', 'namespace', 'project'],
            ],
        ]);
    }

    /**
     * The legitimate forms next to the refused one: a guarded read, per-level
     * formulas that read the metric only where it is published, and a fallback
     * whose left side is published at the level (the right side is then never
     * read).
     */
    #[Test]
    public function itAcceptsACrossLevelReadThatIsGuardedOrNeverReached(): void
    {
        $result = $this->resolve([
            'computed.cls-only' => ['formula' => 'm["size.method-count"] + 1', 'levels' => ['class']],
            'computed.everywhere' => ['formula' => '1', 'levels' => ['class', 'namespace', 'project']],
            'computed.guarded' => ['formula' => 'm["computed.cls-only"] ?? 0', 'levels' => ['project']],
            'computed.per-level' => [
                'formulas' => ['class' => 'm["computed.cls-only"] * 2', 'project' => '(m["computed.cls-only"] ?? 0) + 1'],
                'levels' => ['class', 'project'],
            ],
            'computed.left-published' => [
                'formula' => 'm["computed.everywhere"] ?? m["computed.cls-only"]',
                'levels' => ['project'],
            ],
            'computed.falls-through' => [
                'formula' => 'm["computed.cls-only"] ?? m["computed.everywhere"]',
                'levels' => ['project'],
            ],
        ]);

        foreach (['computed.guarded', 'computed.per-level', 'computed.left-published', 'computed.falls-through'] as $name) {
            self::assertNotNull($this->findByName($result, $name), $name);
        }
    }

    /**
     * A read only a ternary branch makes may never run: which branch does is a
     * fact about each symbol's values, judged when the formula is evaluated.
     * The condition always runs, so a bare cross-level read there is refused.
     */
    #[Test]
    public function itAcceptsACrossLevelReadOnlyATernaryBranchMakes(): void
    {
        $result = $this->resolve([
            'computed.cls-only' => ['formula' => 'm["size.method-count"] + 1', 'levels' => ['class']],
            'computed.branch' => [
                'formula' => 'm["size.loc"] > 0 ? 1 : m["computed.cls-only"]',
                'levels' => ['project'],
            ],
        ]);

        self::assertNotNull($this->findByName($result, 'computed.branch'));
    }

    #[Test]
    public function itRefusesACrossLevelReadInATernaryCondition(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('reads "computed.cls-only" at level "project", where it is not published (published at: class)');

        $this->resolve([
            'computed.cls-only' => ['formula' => 'm["size.method-count"] + 1', 'levels' => ['class']],
            'computed.condition' => [
                'formula' => 'm["computed.cls-only"] > 0 ? 1 : 0',
                'levels' => ['project'],
            ],
        ]);
    }

    #[Test]
    public function itAcceptsAKnownAggregationSuffixOnACatalogKey(): void
    {
        $result = $this->resolve([
            'computed.aggregate' => [
                'formula' => 'm["complexity.ccn.max"] + 1',
                'levels' => ['namespace'],
            ],
        ]);

        self::assertNotNull($this->findByName($result, 'computed.aggregate'));
    }

    #[Test]
    public function itThrowsForAnUnknownAggregationSuffix(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('references unknown metric key "complexity.ccn.bogus"');

        $this->resolve([
            'computed.bad-suffix' => [
                'formula' => 'm["complexity.ccn.bogus"] + 1',
                'levels' => ['namespace'],
            ],
        ]);
    }

    /**
     * A built-in `health.*` formula reaches the same
     * {@see ComputedMetricFormulaValidator::validate()} call as a user-defined
     * `computed.*` one: overriding the built-in formula with an unknown key is
     * caught the same way {@see itThrowsForATypoedMetricKey()} catches it on a
     * user formula, which is the one-path claim C1 exists to prove by running it.
     */
    #[Test]
    public function itAppliesTheCatalogCheckToAnOverriddenBuiltInFormulaTheSameWayAsAUserFormula(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('references unknown metric key "complexity.cnn"');

        $this->resolve([
            'health.complexity' => [
                'formula' => 'm["complexity.cnn"] * 10',
            ],
        ]);
    }

    #[Test]
    public function itThrowsForMissingFormulaForLevel(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('has no formula for level');

        $this->resolve([
            'computed.partial' => [
                'formulas' => [
                    'namespace' => 'm["size.loc.avg"] * 2',
                ],
                'levels' => ['class', 'namespace'],
            ],
        ]);
    }

    /**
     * `health.custom` with a formula used to answer "reserved prefix", a
     * different message from the same name with `enabled: false`
     * ({@see itDisablingUnknownHealthDimensionThrowsTailoredError()}). Both
     * paths now raise the one unified "not a known dimension" refusal.
     */
    #[Test]
    public function itThrowsForReservedHealthPrefixOnNewMetric(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Computed metric name "health.custom" is not a known "health.*" dimension');

        $this->resolve([
            'health.custom' => [
                'formula' => 'm["complexity.ccn.avg"] * 10',
            ],
        ]);
    }

    #[Test]
    public function itHandlesFormulaAndFormulasInteraction(): void
    {
        $result = $this->resolve([
            'health.complexity' => [
                'formula' => 'm["complexity.ccn.avg"] * 10',
                'formulas' => [
                    'class' => 'm["complexity.ccn"] * 5',
                ],
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        // formulas per-level takes precedence over formula singular
        self::assertSame('m["complexity.ccn"] * 5', $complexity->getFormulaForLevel(SymbolLevel::Class_));
        // Other levels get the singular formula
        self::assertSame('m["complexity.ccn.avg"] * 10', $complexity->getFormulaForLevel(SymbolLevel::Namespace_));
    }

    /**
     * @return array<string, array{string, SymbolLevel}>
     */
    public static function provideLevelSpellingsAcceptedForAComputedMetric(): array
    {
        return [
            'class' => ['class', SymbolLevel::Class_],
            'namespace' => ['namespace', SymbolLevel::Namespace_],
            'project' => ['project', SymbolLevel::Project],
        ];
    }

    /**
     * `mapLevel()` used to spell the level vocabulary as its own private
     * `match ('class', 'namespace', 'project')`, independent of
     * {@see \Qualimetrix\Core\Symbol\SymbolLevel}.
     * Routing it through `SymbolLevel` must not narrow the spellings a
     * `computed_metrics.*.levels` entry accepts — every spelling accepted
     * before this change is accepted after it too.
     */
    #[Test]
    #[DataProvider('provideLevelSpellingsAcceptedForAComputedMetric')]
    public function itAcceptsEveryLevelSpellingTheOldPrivateWordListAccepted(string $spelling, SymbolLevel $expected): void
    {
        $result = $this->resolve([
            'computed.spelling' => [
                'formula' => 'm["size.loc.avg"]',
                'levels' => [$spelling],
            ],
        ]);

        $custom = $this->findByName($result, 'computed.spelling');
        self::assertNotNull($custom);
        self::assertSame([$expected], $custom->levels);
    }

    /**
     * `callable` and `file` are real words in the level vocabulary
     * ({@see \Qualimetrix\Core\Symbol\SymbolLevel}) that a
     * computed metric cannot report at — {@see ComputedMetricDefinition}'s
     * `formulas` keys are class/namespace/project only. Both the old private
     * word list and the vocabulary-backed replacement refuse them; this pins
     * that the replacement did not accidentally widen the accepted spellings.
     */
    /** @return array<string, array{string}> */
    public static function provideLevelWordsAComputedMetricCannotReportAt(): array
    {
        return [
            // `callable` is pinned in its own right: the repository answers
            // that level for real now, so losing this refusal would quietly
            // switch on a level of reporting nobody decided to add.
            'callable' => ['callable'],
            'file' => ['file'],
        ];
    }

    #[Test]
    #[DataProvider('provideLevelWordsAComputedMetricCannotReportAt')]
    public function itStillRefusesLevelsAComputedMetricCannotReportAt(string $spelling): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage(\sprintf(
            'Computed metric level "%s" is not supported; computed metrics report at "class", "namespace" or "project" only.',
            $spelling,
        ));

        $this->resolve([
            'computed.unsupported' => [
                'formula' => 'm["size.loc.avg"]',
                'levels' => [$spelling],
            ],
        ]);
    }

    #[Test]
    public function itThrowsForAWordThatIsNotALevelAtAll(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Invalid computed metric level: "bogus"');

        $this->resolve([
            'computed.bogus' => [
                'formula' => 'm["size.loc.avg"]',
                'levels' => ['bogus'],
            ],
        ]);
    }

    #[Test]
    public function itSupportsLevelsFullReplacement(): void
    {
        $result = $this->resolve([
            'health.complexity' => [
                'levels' => ['class'],
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        self::assertSame([SymbolLevel::Class_], $complexity->levels);
    }

    #[Test]
    public function itUsesDefaultLevelsForUserDefined(): void
    {
        $result = $this->resolve([
            'computed.simple' => [
                'formula' => 'm["size.loc.avg"]',
            ],
        ]);

        $custom = $this->findByName($result, 'computed.simple');
        self::assertNotNull($custom);
        // Default levels for user-defined: namespace, project
        self::assertContains(SymbolLevel::Namespace_, $custom->levels);
        self::assertContains(SymbolLevel::Project, $custom->levels);
        self::assertNotContains(SymbolLevel::Class_, $custom->levels);
    }

    #[Test]
    public function itThresholdShorthandSetsBothValues(): void
    {
        $result = $this->resolve([
            'health.complexity' => [
                'threshold' => 45.0,
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        self::assertSame(45.0, $complexity->warningThreshold);
        self::assertSame(45.0, $complexity->errorThreshold);
    }

    #[Test]
    public function itKeepsTheBuiltInErrorWhenOnlyWarningIsWritten(): void
    {
        $complexity = $this->findByName($this->resolve(['health.complexity' => ['warning' => 70]]), 'health.complexity');

        self::assertNotNull($complexity);
        self::assertSame(70.0, $complexity->warningThreshold);
        self::assertSame(25.0, $complexity->errorThreshold);
    }

    #[Test]
    public function itThresholdNullFallsBackToDefaults(): void
    {
        $result = $this->resolve([
            'health.complexity' => [
                'threshold' => null,
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        // Should keep defaults, not set both to null
        self::assertSame(50.0, $complexity->warningThreshold);
        self::assertSame(25.0, $complexity->errorThreshold);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideThresholdMixedWithItsTargets(): iterable
    {
        yield 'warning' => [['threshold' => 45.0, 'warning' => 60.0]];
        yield 'error' => [['threshold' => 45.0, 'error' => 30.0]];
    }

    /** @param array<string, mixed> $entry */
    #[Test]
    #[DataProvider('provideThresholdMixedWithItsTargets')]
    public function itRefusesTheThresholdShorthandBesideOneOfItsTargetsInOneLayer(array $entry): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"threshold" is shorthand for "warning" and "error"');

        $this->resolve(['health.complexity' => $entry]);
    }

    /**
     * What used to be a `TypeError`
     * out of `mapLevel(string)` is now a refusal raised before `array_map()`
     * ever runs, and `mapLevel()` is provably never called with a non-string.
     */
    #[Test]
    public function itRefusesAMapWhereLevelsExpectsAListInsteadOfCrashing(): void
    {
        try {
            $this->resolve([
                'computed.x' => [
                    'formula' => '1+1',
                    'levels' => ['class' => ['warning' => 1]],
                ],
            ]);
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'computed.x', 'levels'], $position->segments);
        }
    }

    #[Test]
    public function itRefusesTheFirstUnknownKeyInDocumentOrder(): void
    {
        try {
            $this->resolve([
                'health.complexity' => ['warnign' => 60, 'erorr' => 30],
            ]);
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'health.complexity', 'warnign'], $position->segments);
            self::assertSame('warnign', $position->written);
            self::assertTrue($position->closed);
            self::assertStringContainsString('(did you mean "warning"?)', $refusal->summary());
        }
    }

    /** A disabled entry is read in full: its unknown key is still refused. */
    #[Test]
    public function itRefusesAnUnknownKeyEvenWhenTheEntryIsDisabled(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "computed_metrics.computed.x.bogusKey"');

        $this->resolve([
            'computed.x' => ['formula' => '1', 'enabled' => false, 'bogusKey' => 1],
        ]);
    }

    #[Test]
    public function itAcceptsANullEntryAsAnAbsentOverride(): void
    {
        $result = $this->resolve(['health.complexity' => null]);

        self::assertCount(6, $result);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function provideValuesOfTheWrongForm(): iterable
    {
        yield 'entry not a map' => [['computed.x' => 5], '"computed_metrics.computed.x" in configuration file "qmx.yaml" must be a map, got int.'];
        yield 'entry false' => [['computed.x' => false], '"computed_metrics.computed.x" in configuration file "qmx.yaml" must be a map, got bool.'];
        yield 'formula not a string' => [['computed.x' => ['formula' => 5]], '"computed_metrics.computed.x.formula" in configuration file "qmx.yaml" must be string, got int.'];
        yield 'description not a string' => [['computed.x' => ['formula' => '1', 'description' => 5]], '"computed_metrics.computed.x.description" in configuration file "qmx.yaml" must be string, got int.'];
        yield 'inverted not a boolean' => [['computed.x' => ['formula' => '1', 'inverted' => 'yes']], '"computed_metrics.computed.x.inverted" in configuration file "qmx.yaml" must be boolean, got string.'];
        yield 'enabled not a boolean' => [['health.complexity' => ['enabled' => 'false']], '"computed_metrics.health.complexity.enabled" in configuration file "qmx.yaml" must be boolean, got string.'];
        yield 'threshold not a number' => [['health.complexity' => ['threshold' => 'abc']], '"computed_metrics.health.complexity.threshold" in configuration file "qmx.yaml" must be number, got string.'];
        yield 'warning not a number' => [['health.complexity' => ['warning' => 'abc']], '"computed_metrics.health.complexity.warning" in configuration file "qmx.yaml" must be number, got string.'];
        yield 'error not a number' => [['health.complexity' => ['error' => 'abc']], '"computed_metrics.health.complexity.error" in configuration file "qmx.yaml" must be number, got string.'];
        yield 'formulas element not a string' => [['computed.x' => ['formulas' => ['class' => 5]]], '"computed_metrics.computed.x.formulas.class" in configuration file "qmx.yaml" must be string, got int.'];
        yield 'formulas not a map' => [['computed.x' => ['formulas' => '1+1']], '"computed_metrics.computed.x.formulas" in configuration file "qmx.yaml" must be a map, got string.'];
        yield 'levels a scalar' => [['computed.x' => ['formula' => '1', 'levels' => 'class']], '"computed_metrics.computed.x.levels" in configuration file "qmx.yaml" must be a list, got string.'];
        yield 'levels a map' => [['computed.x' => ['formula' => '1', 'levels' => ['class' => 'x']]], '"computed_metrics.computed.x.levels" in configuration file "qmx.yaml" must be a list, got a map.'];
        yield 'levels item not a string' => [['computed.x' => ['formula' => '1', 'levels' => [5]]], '"computed_metrics.computed.x.levels[0]" in configuration file "qmx.yaml" must be string, got int.'];
    }

    /**
     * The form of every value is judged by the document, in the words of
     * the file: the key path as written and the file that wrote it.
     *
     * @param array<string, mixed> $computedMetrics
     */
    #[Test]
    #[DataProvider('provideValuesOfTheWrongForm')]
    public function itRefusesAValueOfTheWrongFormInTheFilesWords(array $computedMetrics, string $expected): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage($expected);

        $this->resolve($computedMetrics);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, list<string>, string}> */
    public static function provideInvalidFormsReplacedByAHigherLayer(): iterable
    {
        foreach (['bogus', 'callable', 'file', 'Class'] as $word) {
            yield 'level word ' . $word => [
                ['levels' => [$word]],
                ['levels' => ['namespace']],
                ['levels', '0'],
                $word,
            ];
        }
        yield 'duplicate levels' => [
            ['levels' => ['namespace', 'namespace']],
            ['levels' => ['namespace']],
            ['levels'],
            'declares the same level more than once',
        ];
        yield 'singular formula syntax' => [
            ['formula' => '('],
            ['formula' => '1'],
            ['formula'],
            'Invalid formula syntax',
        ];
        foreach (['class', 'namespace', 'project'] as $level) {
            yield $level . ' formula syntax' => [
                ['formulas' => [$level => '(']],
                ['formulas' => [$level => '1']],
                ['formulas', $level],
                'Invalid formula syntax',
            ];
        }
        foreach (['m.offsetGet("size.loc")', 'm[1 + 1]'] as $formula) {
            yield 'singular nonliteral access ' . $formula => [
                ['formula' => $formula],
                ['formula' => '1'],
                ['formula'],
                'something other than a quoted metric key',
            ];
            yield 'per-level nonliteral access ' . $formula => [
                ['formulas' => ['namespace' => $formula]],
                ['formulas' => ['namespace' => '1']],
                ['formulas', 'namespace'],
                'something other than a quoted metric key',
            ];
        }
    }

    /**
     * @param array<string, mixed> $lower
     * @param array<string, mixed> $higher
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideInvalidFormsReplacedByAHigherLayer')]
    public function itRefusesAReplacedInvalidFormInTheLayerThatWroteIt(array $lower, array $higher, array $path, string $message): void
    {
        try {
            self::composeMetricLayers($lower, $higher);
            self::fail('The higher layer must not hide a lower-layer form error');
        } catch (ConfigurationRefusal $refusal) {
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::Preset, $refusal->sources()[0]->source());
            self::assertSame('team', $refusal->sources()[0]->locator());
            self::assertSame(['computed_metrics', 'computed.custom', ...$path], $refusal->position()?->segments);
            self::assertStringContainsString($message, $refusal->summary());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideContextualReferencesReplacedByAHigherLayer(): iterable
    {
        yield 'unknown computed metric' => ['m["computed.absent"]'];
        yield 'unknown base metric' => ['m["absent.metric"]'];
        yield 'metric absent at a reporting level' => ['m["size.loc"]'];
    }

    #[Test]
    #[DataProvider('provideContextualReferencesReplacedByAHigherLayer')]
    public function itJudgesContextualFormulaReferencesOnlyInTheWinningFormula(string $formula): void
    {
        $result = $this->resolver->resolveWithSources(self::composeMetricLayers(['formula' => $formula], ['formula' => '1']))->all();
        $definition = $this->findByName($result, 'computed.custom');

        self::assertNotNull($definition);
        self::assertSame('1', $definition->getFormulaForLevel(SymbolLevel::Namespace_));
    }

    /**
     * @param array<string, mixed> $lower
     * @param array<string, mixed> $higher
     */
    private static function composeMetricLayers(array $lower, array $higher): ResolvedDocument
    {
        return DocumentComposer::compose(
            new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]),
            [
                new AuthoredLayer(
                    ConfigurationOrigin::of(ConfigurationSource::Preset, 'team'),
                    AuthoredNode::fromPlain(['computed_metrics' => ['computed.custom' => $lower]]),
                ),
                new AuthoredLayer(
                    ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
                    AuthoredNode::fromPlain(['computed_metrics' => ['computed.custom' => $higher]]),
                ),
            ],
        );
    }

    /**
     * One configuration file writing the two sections, read by the document
     * engine the way `qmx.yaml` is.
     *
     * @param array<string, mixed> $computedMetrics
     * @param list<mixed> $excludeHealth
     *
     * @return list<ComputedMetricDefinition>
     */
    private function resolve(array $computedMetrics = [], array $excludeHealth = []): array
    {
        $written = array_filter(
            [ComputedMetricsSection::KEY => $computedMetrics, ExcludeHealthSection::KEY => $excludeHealth],
            static fn(array $section): bool => $section !== [],
        );

        return $this->resolver->resolve(DocumentComposer::compose(
            new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]),
            [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), AuthoredNode::fromPlain($written))],
        ));
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function findByName(array $definitions, string $name): ?ComputedMetricDefinition
    {
        foreach ($definitions as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        return null;
    }
}
