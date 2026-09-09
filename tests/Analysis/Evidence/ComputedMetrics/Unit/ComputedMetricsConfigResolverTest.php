<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
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
    public function itResolveWithEmptyConfigReturns6Defaults(): void
    {
        $result = $this->resolver->resolve([]);

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
        $result = $this->resolver->resolve([
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
        $result = $this->resolver->resolve([
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
        $result = $this->resolver->resolve([
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
        $result = $this->resolver->resolve([
            'health.typing' => [
                'enabled' => false,
            ],
        ]);

        // 6 defaults - 1 disabled = 5 (health.overall stays)
        self::assertCount(5, $result);
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.typing', $names);
        self::assertContains('health.overall', $names);

        // health.overall formula must no longer reference health__typing,
        // and remaining weights must sum to ~1.0
        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);

        $classFormula = $overall->formulas['class'] ?? '';
        self::assertStringNotContainsString('m["health.typing"]', $classFormula);
        preg_match_all('/\*\s*([\d.]+)/', $classFormula, $matches);
        $weights = array_map('floatval', $matches[1]);
        self::assertEqualsWithDelta(1.0, array_sum($weights), 0.001);
    }

    #[Test]
    public function itDisablesOverallDimensionDirectly(): void
    {
        // Disabling health.overall directly is also supported — it has no dependents,
        // so sub-dimensions are untouched.
        $result = $this->resolver->resolve([
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
        $result = $this->resolver->resolve(
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
        $result = $this->resolver->resolve([
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

        $this->resolver->resolve([
            'health.typying' => [
                'enabled' => false,
            ],
        ]);
    }

    /**
     * The position pins the split rule of `02-computed-metric-keys.md` §2: a
     * `health.*` name is sliced at the reserved prefix, so the position's last
     * segment is the short dimension name, not the whole `health.typying`.
     */
    #[Test]
    public function itPositionsAnUnknownHealthDimensionAtTheShortName(): void
    {
        try {
            $this->resolver->resolve(['health.typying' => ['enabled' => false]]);
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'health', 'typying'], $position->segments());
            self::assertSame('typying', $position->written());
            self::assertTrue($position->isClosed());
            self::assertSame(
                ['cohesion', 'complexity', 'coupling', 'maintainability', 'overall', 'typing'],
                $position->accepted(),
            );
        }
    }

    #[Test]
    public function itExcludeHealthAcceptsBothNameForms(): void
    {
        // Both bare ('typing') and fully-qualified ('health.typing') forms must be accepted
        // in the excludeHealth arg, and dedupe across them.
        $result = $this->resolver->resolve([], ['typing', 'health.typing']);

        // 6 defaults - 1 excluded = 5
        self::assertCount(5, $result);
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.typing', $names);
    }

    #[Test]
    public function itThrowsForUnknownExcludeHealthArg(): void
    {
        // Unknown name in the excludeHealth arg must surface as an error from
        // HealthFormulaExcluder (different source than enabled:false, different message).
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/Unknown health dimension.*nonexistent/');

        $this->resolver->resolve([], ['nonexistent']);
    }

    #[Test]
    public function itCreatesNewComputedMetric(): void
    {
        $result = $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
            'health.overall' => ['levels' => ['namespace', 'namespace']],
        ]);
    }

    /**
     * A level is a coordinate beside the channel name, addressed via
     * `channel:level`, never a word inside the name itself — the same
     * invariant Ш5c enforces for statically declared channels
     * ({@see \Qualimetrix\Tests\Analysis\Finding\Integration\ChannelLevelAssemblyTopologyTest}).
     * A user-defined metric name is the one place that invariant can still be
     * broken at runtime, since the user picks the name.
     */
    #[Test]
    public function itRefusesAUserDefinedMetricNameEndingInALevelWord(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('must not end in the level word "class"');

        $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
            'computed.ref' => [
                'formula' => 'm["computed.nonexistent"] + 1',
                'levels' => ['namespace'],
            ],
        ]);
    }

    #[Test]
    public function itAcceptsAFormulaReferencingAKnownCatalogKey(): void
    {
        $result = $this->resolver->resolve([
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

        $this->resolver->resolve([
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
        $result = $this->resolver->resolve([
            'computed.derived' => [
                'formula' => 'm["health.complexity"] + 1',
                'levels' => ['namespace'],
            ],
        ]);

        self::assertNotNull($this->findByName($result, 'computed.derived'));
    }

    #[Test]
    public function itAcceptsAKnownAggregationSuffixOnACatalogKey(): void
    {
        $result = $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
            'health.custom' => [
                'formula' => 'm["complexity.ccn.avg"] * 10',
            ],
        ]);
    }

    #[Test]
    public function itHandlesFormulaAndFormulasInteraction(): void
    {
        $result = $this->resolver->resolve([
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
        $result = $this->resolver->resolve([
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

        $this->resolver->resolve([
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

        $this->resolver->resolve([
            'computed.bogus' => [
                'formula' => 'm["size.loc.avg"]',
                'levels' => ['bogus'],
            ],
        ]);
    }

    #[Test]
    public function itSupportsLevelsFullReplacement(): void
    {
        $result = $this->resolver->resolve([
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
        $result = $this->resolver->resolve([
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
        $result = $this->resolver->resolve([
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
    public function itThresholdNullFallsBackToDefaults(): void
    {
        $result = $this->resolver->resolve([
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

    #[Test]
    public function itThrowsWhenThresholdMixedWithWarning(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Cannot mix "threshold"');

        $this->resolver->resolve([
            'health.complexity' => [
                'threshold' => 45.0,
                'warning' => 60.0,
            ],
        ]);
    }

    #[Test]
    public function itThrowsWhenThresholdMixedWithError(): void
    {
        self::expectException(ConfigurationRefusal::class);

        $this->resolver->resolve([
            'health.complexity' => [
                'threshold' => 45.0,
                'error' => 30.0,
            ],
        ]);
    }

    /**
     * The two measured crashes `02-computed-metric-keys.md` §9 names as the
     * regression this stage exists to close: what used to be a `TypeError`
     * out of `mapLevel(string)` is now a refusal raised before `array_map()`
     * ever runs, and `mapLevel()` is provably never called with a non-string.
     */
    #[Test]
    public function itRefusesAMapWhereLevelsExpectsAListInsteadOfCrashing(): void
    {
        try {
            $this->resolver->resolve([
                'computed.x' => [
                    'formula' => '1+1',
                    'levels' => ['class' => ['warning' => 1]],
                ],
            ]);
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'computed.x', 'levels'], $position->segments());
        }
    }

    #[Test]
    public function itRefusesTheFirstUnknownKeyInDocumentOrder(): void
    {
        try {
            $this->resolver->resolve([
                'health.complexity' => ['warnign' => 60, 'erorr' => 30],
            ]);
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'health', 'complexity', 'warnign'], $position->segments());
            self::assertSame('warnign', $position->written());
            self::assertSame(
                ['description', 'enabled', 'error', 'formula', 'formulas', 'inverted', 'levels', 'threshold', 'warning'],
                $position->accepted(),
            );
            self::assertTrue($position->isClosed());
        }
    }

    /**
     * An unknown key on a *record* refuses even when the entry also carries
     * `enabled: false` — the ordering this stage's §6 fixes, because the walk
     * now runs before the `enabled` branch instead of never running at all.
     */
    #[Test]
    public function itRefusesAnUnknownKeyEvenWhenTheEntryIsDisabled(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "bogusKey"');

        $this->resolver->resolve([
            'computed.x' => ['formula' => '1', 'enabled' => false, 'bogusKey' => 1],
        ]);
    }

    #[Test]
    public function itRefusesAnEntryThatIsNotAMap(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('must be a map of options');

        $this->resolver->resolve(['computed.x' => 5]);
    }

    #[Test]
    public function itAcceptsANullEntryAsAnAbsentOverride(): void
    {
        $result = $this->resolver->resolve(['health.complexity' => null]);

        self::assertCount(6, $result);
    }

    #[Test]
    public function itRefusesAnEntryThatIsFalseWithAHintAboutEnabledFalse(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('{enabled: false}');

        $this->resolver->resolve(['computed.x' => false]);
    }

    #[Test]
    public function itRefusesANonStringFormulaShorthand(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "formula" of computed metric "computed.x" must be a string');

        $this->resolver->resolve([
            'computed.x' => ['formula' => 5, 'levels' => ['namespace']],
        ]);
    }

    #[Test]
    public function itRefusesANonStringDescription(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "description" of computed metric "computed.x" must be a string');

        $this->resolver->resolve([
            'computed.x' => ['formula' => '1', 'levels' => ['namespace'], 'description' => 5],
        ]);
    }

    #[Test]
    public function itRefusesANonBooleanInverted(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "inverted" of computed metric "computed.x" must be a boolean');

        $this->resolver->resolve([
            'computed.x' => ['formula' => '1', 'levels' => ['namespace'], 'inverted' => 'yes'],
        ]);
    }

    #[Test]
    public function itRefusesANonBooleanEnabled(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "enabled" of computed metric "health.complexity" must be a boolean');

        $this->resolver->resolve(['health.complexity' => ['enabled' => 'false']]);
    }

    #[Test]
    public function itRefusesANonNumericThreshold(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "threshold" of computed metric "health.complexity" must be a number or null');

        $this->resolver->resolve(['health.complexity' => ['threshold' => 'abc']]);
    }

    /**
     * `warning: abc` used to silently drop the threshold to `null` instead of
     * refusing — a change in behaviour, not just a missed value, because it
     * altered which findings the metric produced without saying a word.
     */
    #[Test]
    public function itRefusesANonNumericWarning(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "warning" of computed metric "health.complexity" must be a number or null');

        $this->resolver->resolve(['health.complexity' => ['warning' => 'abc']]);
    }

    #[Test]
    public function itRefusesANonNumericError(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Option "error" of computed metric "health.complexity" must be a number or null');

        $this->resolver->resolve(['health.complexity' => ['error' => 'abc']]);
    }

    #[Test]
    public function itRefusesANonStringFormulasElement(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('The "formulas.class" value of computed metric "computed.x" must be a string');

        $this->resolver->resolve([
            'computed.x' => ['formulas' => ['class' => 5], 'levels' => ['class']],
        ]);
    }

    #[Test]
    public function itRefusesLevelsWrittenAsAScalarInsteadOfAList(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"levels" of computed metric "computed.x" must be a list');

        $this->resolver->resolve([
            'computed.x' => ['formula' => '1', 'levels' => 'class'],
        ]);
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
