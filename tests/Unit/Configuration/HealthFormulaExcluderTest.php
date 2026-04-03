<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(HealthFormulaExcluder::class)]
final class HealthFormulaExcluderTest extends TestCase
{
    private HealthFormulaExcluder $excluder;

    protected function setUp(): void
    {
        $this->excluder = new HealthFormulaExcluder();
    }

    public function testNoExclusionsReturnsSameDefinitions(): void
    {
        $definitions = ComputedMetricDefaults::getDefaults();
        $definitions = array_values($definitions);

        $result = $this->excluder->applyExcludeHealth($definitions, []);

        self::assertSame($definitions, $result);
    }

    public function testExcludeOneDimensionRemovesIt(): void
    {
        $definitions = array_values(ComputedMetricDefaults::getDefaults());

        $result = $this->excluder->applyExcludeHealth($definitions, ['typing']);

        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.typing', $names);
        self::assertContains('health.complexity', $names);
        self::assertContains('health.cohesion', $names);
        self::assertContains('health.coupling', $names);
        self::assertContains('health.maintainability', $names);
        self::assertContains('health.overall', $names);
    }

    public function testExcludeWithFullPrefixAlsoWorks(): void
    {
        $definitions = array_values(ComputedMetricDefaults::getDefaults());

        $result = $this->excluder->applyExcludeHealth($definitions, ['health.typing']);

        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.typing', $names);
    }

    public function testExcludeOneDimensionRenormalizesWeights(): void
    {
        // Create simple definitions with a known overall formula
        $definitions = $this->createSimpleDefinitions([
            'health.a' => 0.4,
            'health.b' => 0.3,
            'health.c' => 0.3,
        ]);

        $result = $this->excluder->applyExcludeHealth($definitions, ['a']);

        // After excluding 'a' (0.4), remaining weights 0.3 + 0.3 = 0.6
        // Normalized: b = 0.3/0.6 = 0.5, c = 0.3/0.6 = 0.5
        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);

        // Verify the formula contains normalized weights
        $formula = $overall->formulas['class'] ?? '';
        self::assertStringContainsString('health__b', $formula);
        self::assertStringContainsString('health__c', $formula);
        self::assertStringNotContainsString('health__a', $formula);

        // Extract weights from formula and verify they sum to 1.0
        preg_match_all('/\*\s*([\d.]+)/', $formula, $matches);
        $weights = array_map('floatval', $matches[1]);
        self::assertEqualsWithDelta(1.0, array_sum($weights), 0.001);
        self::assertEqualsWithDelta(0.5, $weights[0], 0.001);
        self::assertEqualsWithDelta(0.5, $weights[1], 0.001);
    }

    public function testExcludeMultipleDimensions(): void
    {
        $definitions = $this->createSimpleDefinitions([
            'health.a' => 0.5,
            'health.b' => 0.3,
            'health.c' => 0.2,
        ]);

        $result = $this->excluder->applyExcludeHealth($definitions, ['a', 'b']);

        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.a', $names);
        self::assertNotContains('health.b', $names);
        self::assertContains('health.c', $names);

        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);

        $formula = $overall->formulas['class'] ?? '';
        // Only 'c' remains, weight normalized to 1.0
        self::assertStringContainsString('health__c', $formula);
        preg_match_all('/\*\s*([\d.]+)/', $formula, $matches);
        self::assertEqualsWithDelta(1.0, (float) $matches[1][0], 0.001);
    }

    public function testExcludeAllSubDimensionsRemovesOverall(): void
    {
        $definitions = $this->createSimpleDefinitions([
            'health.a' => 0.5,
            'health.b' => 0.5,
        ]);

        $result = $this->excluder->applyExcludeHealth($definitions, ['a', 'b']);

        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.a', $names);
        self::assertNotContains('health.b', $names);
        self::assertNotContains('health.overall', $names);
        self::assertSame([], $result);
    }

    public function testUnknownDimensionThrowsException(): void
    {
        $excluder = new HealthFormulaExcluder();
        $definitions = array_values(ComputedMetricDefaults::getDefaults());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown health dimension.*health\.nonexistent/');

        $excluder->applyExcludeHealth($definitions, ['nonexistent']);
    }

    public function testExcludingOverallDimensionDoesNotThrow(): void
    {
        $excluder = new HealthFormulaExcluder();
        $definitions = array_values(ComputedMetricDefaults::getDefaults());

        $result = $excluder->applyExcludeHealth($definitions, ['health.overall']);

        // health.overall itself is excluded
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.overall', $names);
        // Sub-dimensions remain
        self::assertContains('health.complexity', $names);
    }

    public function testOverallFormulaMultipleLevelsAreAllRebuilt(): void
    {
        $definitions = array_values(ComputedMetricDefaults::getDefaults());

        $result = $this->excluder->applyExcludeHealth($definitions, ['typing']);

        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);

        // Both class and namespace formulas should be rebuilt
        self::assertArrayHasKey('class', $overall->formulas);
        self::assertArrayHasKey('namespace', $overall->formulas);

        // Neither should contain typing
        self::assertStringNotContainsString('health__typing', $overall->formulas['class']);
        self::assertStringNotContainsString('health__typing', $overall->formulas['namespace']);
    }

    public function testRebuiltFormulaWrappedInClamp(): void
    {
        $definitions = $this->createSimpleDefinitions([
            'health.a' => 0.6,
            'health.b' => 0.4,
        ]);

        $result = $this->excluder->applyExcludeHealth($definitions, ['b']);

        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);

        $formula = $overall->formulas['class'] ?? '';
        self::assertStringStartsWith('clamp(', $formula);
        self::assertStringEndsWith(', 0, 100)', $formula);
    }

    public function testRebuiltDefinitionPreservesOtherFields(): void
    {
        $definitions = array_values(ComputedMetricDefaults::getDefaults());

        $result = $this->excluder->applyExcludeHealth($definitions, ['typing']);

        $overall = $this->findByName($result, 'health.overall');
        self::assertNotNull($overall);

        self::assertSame('health.overall', $overall->name);
        self::assertSame('Overall health score (0-100, higher is better)', $overall->description);
        self::assertTrue($overall->inverted);
        self::assertSame(50.0, $overall->warningThreshold);
        self::assertSame(30.0, $overall->errorThreshold);
    }

    /**
     * Creates a minimal set of definitions: N sub-dimensions + health.overall with known weights.
     *
     * @param array<string, float> $dimensionWeights e.g. ['health.a' => 0.4, 'health.b' => 0.6]
     *
     * @return list<ComputedMetricDefinition>
     */
    private function createSimpleDefinitions(array $dimensionWeights): array
    {
        $definitions = [];

        foreach ($dimensionWeights as $name => $weight) {
            $definitions[] = new ComputedMetricDefinition(
                name: $name,
                formulas: ['class' => 'clamp(100, 0, 100)'],
                description: $name . ' description',
                levels: [SymbolType::Class_],
                inverted: true,
            );
        }

        // Build overall formula from weights
        $terms = [];
        foreach ($dimensionWeights as $dim => $weight) {
            $varName = str_replace('.', '__', $dim);
            $terms[] = \sprintf('(%s ?? 75) * %s', $varName, $weight);
        }
        $overallFormula = \sprintf('clamp(%s, 0, 100)', implode(' + ', $terms));

        $definitions[] = new ComputedMetricDefinition(
            name: 'health.overall',
            formulas: ['class' => $overallFormula],
            description: 'Overall health score (0-100, higher is better)',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );

        return $definitions;
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function findByName(array $definitions, string $name): ?ComputedMetricDefinition
    {
        foreach ($definitions as $def) {
            if ($def->name === $name) {
                return $def;
            }
        }

        return null;
    }
}
