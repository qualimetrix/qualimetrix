<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ComputedMetricFormulaValidator;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
use Qualimetrix\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolType;
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
                'formula' => '100 - ccn__avg * 10',
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        // Singular formula applies to all levels
        self::assertSame('100 - ccn__avg * 10', $complexity->getFormulaForLevel(SymbolType::Class_));
        self::assertSame('100 - ccn__avg * 10', $complexity->getFormulaForLevel(SymbolType::Namespace_));
        self::assertSame('100 - ccn__avg * 10', $complexity->getFormulaForLevel(SymbolType::Project));
    }

    #[Test]
    public function itOverridesHealthFormulasPerLevel(): void
    {
        $result = $this->resolver->resolve([
            'health.complexity' => [
                'formulas' => [
                    'class' => '100 - ccn * 5',
                ],
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        // Only class formula overridden
        self::assertSame('100 - ccn * 5', $complexity->getFormulaForLevel(SymbolType::Class_));
        // Namespace keeps default formula (uses per-method average via ccn__sum / symbolMethodCount)
        self::assertStringContainsString('ccn__sum', (string) $complexity->getFormulaForLevel(SymbolType::Namespace_));
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
        self::assertStringNotContainsString('health__typing', $classFormula);
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
        self::assertStringNotContainsString('health__typing', $overall->formulas['namespace'] ?? '');
        self::assertStringNotContainsString('health__maintainability', $overall->formulas['namespace'] ?? '');
    }

    #[Test]
    public function itDisablesUserComputedMetric(): void
    {
        // Custom `computed.*` metrics disabled via `enabled: false` are simply removed —
        // no formula renormalization applies (no health.overall reference path).
        $result = $this->resolver->resolve([
            'computed.foo' => [
                'formula' => 'loc__avg * 2',
                'levels' => ['namespace'],
                'enabled' => false,
            ],
        ]);

        self::assertCount(6, $result); // defaults untouched, custom rejected
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('computed.foo', $names);
    }

    #[Test]
    public function itDisablingUnknownHealthDimensionThrowsTailoredError(): void
    {
        // Typo in the YAML key for `enabled: false` on a health metric must produce an
        // error that points at the actual source (`computed_metrics.health.X.enabled`),
        // not at `--exclude-health` / `exclude_health`.
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Unknown health dimension "health.typying"');
        self::expectExceptionMessage('computed_metrics.health.typying.enabled: false');

        $this->resolver->resolve([
            'health.typying' => [
                'enabled' => false,
            ],
        ]);
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
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessageMatches('/Unknown health dimension.*nonexistent/');

        $this->resolver->resolve([], ['nonexistent']);
    }

    #[Test]
    public function itCreatesNewComputedMetric(): void
    {
        $result = $this->resolver->resolve([
            'computed.my_score' => [
                'formula' => 'loc__avg * 2',
                'description' => 'My custom score',
                'levels' => ['class', 'namespace'],
                'inverted' => true,
                'warning' => 80.0,
                'error' => 40.0,
            ],
        ]);

        self::assertCount(7, $result); // 6 defaults + 1 custom
        $custom = $this->findByName($result, 'computed.my_score');
        self::assertNotNull($custom);
        self::assertSame('My custom score', $custom->description);
        self::assertTrue($custom->inverted);
        self::assertSame(80.0, $custom->warningThreshold);
        self::assertSame(40.0, $custom->errorThreshold);
        self::assertSame('loc__avg * 2', $custom->getFormulaForLevel(SymbolType::Class_));
        self::assertContains(SymbolType::Class_, $custom->levels);
        self::assertContains(SymbolType::Namespace_, $custom->levels);
        self::assertNotContains(SymbolType::Project, $custom->levels);
    }

    #[Test]
    public function itThrowsForInvalidPrefix(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must start with "health." or "computed."');

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
                'formula' => 'computed__b + 1',
                'levels' => ['namespace'],
            ],
            'computed.b' => [
                'formula' => 'computed__a + 1',
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
                'formula' => 'computed__nonexistent + 1',
                'levels' => ['namespace'],
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
                    'namespace' => 'loc__avg * 2',
                ],
                'levels' => ['class', 'namespace'],
            ],
        ]);
    }

    #[Test]
    public function itThrowsForReservedHealthPrefixOnNewMetric(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('reserved "health.*" prefix');

        $this->resolver->resolve([
            'health.custom' => [
                'formula' => 'ccn__avg * 10',
            ],
        ]);
    }

    #[Test]
    public function itHandlesFormulaAndFormulasInteraction(): void
    {
        $result = $this->resolver->resolve([
            'health.complexity' => [
                'formula' => 'ccn__avg * 10',
                'formulas' => [
                    'class' => 'ccn * 5',
                ],
            ],
        ]);

        $complexity = $this->findByName($result, 'health.complexity');
        self::assertNotNull($complexity);
        // formulas per-level takes precedence over formula singular
        self::assertSame('ccn * 5', $complexity->getFormulaForLevel(SymbolType::Class_));
        // Other levels get the singular formula
        self::assertSame('ccn__avg * 10', $complexity->getFormulaForLevel(SymbolType::Namespace_));
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
        self::assertSame([SymbolType::Class_], $complexity->levels);
    }

    #[Test]
    public function itUsesDefaultLevelsForUserDefined(): void
    {
        $result = $this->resolver->resolve([
            'computed.simple' => [
                'formula' => 'loc__avg',
            ],
        ]);

        $custom = $this->findByName($result, 'computed.simple');
        self::assertNotNull($custom);
        // Default levels for user-defined: namespace, project
        self::assertContains(SymbolType::Namespace_, $custom->levels);
        self::assertContains(SymbolType::Project, $custom->levels);
        self::assertNotContains(SymbolType::Class_, $custom->levels);
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
        self::expectException(InvalidArgumentException::class);
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
        self::expectException(InvalidArgumentException::class);

        $this->resolver->resolve([
            'health.complexity' => [
                'threshold' => 45.0,
                'error' => 30.0,
            ],
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
