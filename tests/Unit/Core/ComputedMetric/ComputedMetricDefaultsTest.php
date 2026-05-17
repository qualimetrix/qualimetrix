<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\ComputedMetric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(ComputedMetricDefaults::class)]
final class ComputedMetricDefaultsTest extends TestCase
{
    #[Test]
    public function itReturnsSixDefaults(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        self::assertCount(6, $defaults);
    }

    #[Test]
    public function itAllKeysAreHealthPrefixed(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach (array_keys($defaults) as $key) {
            self::assertStringStartsWith('health.', $key);
        }
    }

    #[Test]
    public function itAllDefaultsAreInverted(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertTrue($definition->inverted, \sprintf('Expected "%s" to be inverted', $name));
        }
    }

    #[Test]
    public function itAllDefaultsHaveClassNamespaceProjectLevels(): void
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

    #[Test]
    public function itAllDefaultsHaveClassAndNamespaceFormulas(): void
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

    #[Test]
    public function itProjectFormulaInheritance(): void
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

    #[Test]
    public function itExplicitProjectFormulas(): void
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

    #[Test]
    public function itExpectedKeys(): void
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

    #[Test]
    public function itAllDefaultsAreComputedMetricDefinitionInstances(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $definition) {
            self::assertInstanceOf(ComputedMetricDefinition::class, $definition);
        }
    }

    #[Test]
    public function itAllDefaultsHaveThresholds(): void
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
