<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\ComputedMetricFormulaValidator;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
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
        $this->resolver = new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator());
    }

    public function testResolveWithEmptyConfigReturns6Defaults(): void
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

    public function testOverrideHealthThresholdOnly(): void
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

    public function testOverrideHealthFormulaSingular(): void
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

    public function testOverrideHealthFormulasPerLevel(): void
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

    public function testDisableHealthMetric(): void
    {
        // Disable health.maintainability (not referenced by health.overall at class level,
        // but referenced at namespace level). Also disable health.overall to avoid
        // reference validation errors.
        $result = $this->resolver->resolve([
            'health.maintainability' => [
                'enabled' => false,
            ],
            'health.overall' => [
                'enabled' => false,
            ],
        ]);

        self::assertCount(4, $result);
        $names = array_map(static fn(ComputedMetricDefinition $d): string => $d->name, $result);
        self::assertNotContains('health.maintainability', $names);
        self::assertNotContains('health.overall', $names);
    }

    public function testCreateNewComputedMetric(): void
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

    public function testInvalidPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with "health." or "computed."');

        $this->resolver->resolve([
            'custom.my_score' => [
                'formula' => 'loc * 2',
            ],
        ]);
    }

    public function testFormulaSyntaxErrorThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid formula syntax');

        $this->resolver->resolve([
            'computed.bad' => [
                'formula' => 'loc +* 2',
                'levels' => ['namespace'],
            ],
        ]);
    }

    public function testCircularDependencyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency');

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

    public function testReferenceToNonExistentComputedMetricThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('references unknown metric "computed.nonexistent"');

        $this->resolver->resolve([
            'computed.ref' => [
                'formula' => 'computed__nonexistent + 1',
                'levels' => ['namespace'],
            ],
        ]);
    }

    public function testMissingFormulaForLevelThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no formula for level');

        $this->resolver->resolve([
            'computed.partial' => [
                'formulas' => [
                    'namespace' => 'loc__avg * 2',
                ],
                'levels' => ['class', 'namespace'],
            ],
        ]);
    }

    public function testReservedHealthPrefixForNewMetricThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved "health.*" prefix');

        $this->resolver->resolve([
            'health.custom' => [
                'formula' => 'ccn__avg * 10',
            ],
        ]);
    }

    public function testFormulaAndFormulasInteraction(): void
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

    public function testLevelsFullReplacement(): void
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

    public function testDefaultLevelsForUserDefined(): void
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

    public function testThresholdShorthandSetsBothValues(): void
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

    public function testThresholdNullFallsBackToDefaults(): void
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

    public function testThresholdMixedWithWarningThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot mix "threshold"');

        $this->resolver->resolve([
            'health.complexity' => [
                'threshold' => 45.0,
                'warning' => 60.0,
            ],
        ]);
    }

    public function testThresholdMixedWithErrorThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

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
