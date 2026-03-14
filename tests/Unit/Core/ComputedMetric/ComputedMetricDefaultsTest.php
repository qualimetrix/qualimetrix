<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\ComputedMetric;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefaults;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\Symbol\SymbolType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComputedMetricDefaults::class)]
final class ComputedMetricDefaultsTest extends TestCase
{
    public function testReturnsSixDefaults(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        self::assertCount(6, $defaults);
    }

    public function testAllKeysAreHealthPrefixed(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach (array_keys($defaults) as $key) {
            self::assertStringStartsWith('health.', $key);
        }
    }

    public function testAllDefaultsAreInverted(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertTrue($definition->inverted, \sprintf('Expected "%s" to be inverted', $name));
        }
    }

    public function testAllDefaultsHaveClassNamespaceProjectLevels(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertTrue(
                $definition->hasLevel(SymbolType::Class_),
                \sprintf('Expected "%s" to have Class_ level', $name),
            );
            self::assertTrue(
                $definition->hasLevel(SymbolType::Namespace_),
                \sprintf('Expected "%s" to have Namespace_ level', $name),
            );
            self::assertTrue(
                $definition->hasLevel(SymbolType::Project),
                \sprintf('Expected "%s" to have Project level', $name),
            );
        }
    }

    public function testAllDefaultsHaveClassAndNamespaceFormulas(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertNotNull(
                $definition->getFormulaForLevel(SymbolType::Class_),
                \sprintf('Expected "%s" to have a class formula', $name),
            );
            self::assertNotNull(
                $definition->getFormulaForLevel(SymbolType::Namespace_),
                \sprintf('Expected "%s" to have a namespace formula', $name),
            );
        }
    }

    public function testProjectFormulaInheritance(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        // These should inherit project formula from namespace (no explicit project formula)
        $inheriting = ['health.complexity', 'health.cohesion', 'health.typing', 'health.maintainability', 'health.overall'];

        foreach ($inheriting as $name) {
            $definition = $defaults[$name];
            self::assertSame(
                $definition->getFormulaForLevel(SymbolType::Namespace_),
                $definition->getFormulaForLevel(SymbolType::Project),
                \sprintf('Expected "%s" project formula to inherit from namespace', $name),
            );
        }
    }

    public function testExplicitProjectFormulas(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        // These have explicit project formulas different from namespace
        $explicit = ['health.coupling'];

        foreach ($explicit as $name) {
            $definition = $defaults[$name];
            self::assertNotSame(
                $definition->getFormulaForLevel(SymbolType::Namespace_),
                $definition->getFormulaForLevel(SymbolType::Project),
                \sprintf('Expected "%s" to have an explicit project formula different from namespace', $name),
            );
        }
    }

    public function testExpectedKeys(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        $expectedKeys = [
            'health.complexity',
            'health.cohesion',
            'health.coupling',
            'health.typing',
            'health.maintainability',
            'health.overall',
        ];

        self::assertSame($expectedKeys, array_keys($defaults));
    }

    public function testAllDefaultsAreComputedMetricDefinitionInstances(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $definition) {
            self::assertInstanceOf(ComputedMetricDefinition::class, $definition);
        }
    }

    public function testAllDefaultsHaveThresholds(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertNotNull(
                $definition->warningThreshold,
                \sprintf('Expected "%s" to have a warning threshold', $name),
            );
            self::assertNotNull(
                $definition->errorThreshold,
                \sprintf('Expected "%s" to have an error threshold', $name),
            );
        }
    }
}
