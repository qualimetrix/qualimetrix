<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\HealthVocabulary;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricOutcome;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricSubjectEvaluation;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\MetricLookup;
use Qualimetrix\Core\Symbol\SymbolLevel;

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
    public function itPrefixesEveryDefaultKeyWithHealth(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach (array_keys($defaults) as $key) {
            self::assertStringStartsWith('health.', $key);
        }
    }

    #[Test]
    public function itInvertsEveryDefault(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertTrue($definition->inverted, \sprintf('Expected "%s" to be inverted', $name));
        }
    }

    #[Test]
    public function itDefinesEveryDefaultAtClassNamespaceAndProjectLevel(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertTrue(
                $definition->hasLevel(SymbolLevel::Class_),
                \sprintf('Expected "%s" to have Class_ level', $name),
            );
            self::assertTrue(
                $definition->hasLevel(SymbolLevel::Namespace_),
                \sprintf('Expected "%s" to have Namespace_ level', $name),
            );
            self::assertTrue(
                $definition->hasLevel(SymbolLevel::Project),
                \sprintf('Expected "%s" to have Project level', $name),
            );
        }
    }

    #[Test]
    public function itGivesEveryDefaultAClassAndNamespaceFormula(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $name => $definition) {
            self::assertNotNull(
                $definition->getFormulaForLevel(SymbolLevel::Class_),
                \sprintf('Expected "%s" to have a class formula', $name),
            );
            self::assertNotNull(
                $definition->getFormulaForLevel(SymbolLevel::Namespace_),
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
                $definition->getFormulaForLevel(SymbolLevel::Namespace_),
                $definition->getFormulaForLevel(SymbolLevel::Project),
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
                $definition->getFormulaForLevel(SymbolLevel::Namespace_),
                $definition->getFormulaForLevel(SymbolLevel::Project),
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
    public function itReturnsEveryDefaultAsAComputedMetricDefinition(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();

        foreach ($defaults as $definition) {
            self::assertInstanceOf(ComputedMetricDefinition::class, $definition);
        }
    }

    #[Test]
    public function itGivesEveryDefaultAWarningAndErrorThreshold(): void
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
    #[Test]
    public function itUsesOnlyMeasuredCohesionHalves(): void
    {
        $formula = ComputedMetricDefaults::getDefaults()['health.cohesion']->getFormulaForLevel(SymbolLevel::Class_);
        self::assertNotNull($formula);
        $expression = new ComputedMetricExpression();
        self::assertSame(100.0, $expression->evaluate($formula, ['m' => new MetricLookup(['cohesion.lcom' => 1])]));
        self::assertSame(0.0, $expression->evaluate($formula, ['m' => new MetricLookup(['cohesion.tcc' => 0])]));
        self::assertSame(50.0, $expression->evaluate($formula, ['m' => new MetricLookup(['cohesion.tcc' => 0.25])]));
    }

    #[Test]
    public function itDefinesExactEffectivePolicyInputsAndValidatesEverySuppliedOperand(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();
        foreach ([
            ['health.complexity', 'class', ['complexity.ccn.avg', 'complexity.cognitive.avg', 'complexity.ccn.max', 'complexity.cognitive.max']],
            ['health.complexity', 'namespace', ['complexity.ccn.sum', 'complexity.cognitive.sum', 'complexity.ccn.p95', 'complexity.cognitive.p95', 'complexity.ccn.max']],
            ['health.complexity', 'project', ['complexity.ccn.sum', 'complexity.cognitive.sum', 'complexity.ccn.p95', 'complexity.cognitive.p95', 'complexity.ccn.max']],
            ['health.cohesion', 'class', ['cohesion.tcc', 'cohesion.lcom']],
            ['health.cohesion', 'namespace', ['cohesion.tcc.avg', 'cohesion.lcom.avg']],
            ['health.cohesion', 'project', ['cohesion.tcc.avg', 'cohesion.lcom.avg']],
            ['health.coupling', 'class', []],
            ['health.coupling', 'namespace', ['coupling.distance', 'coupling.ce-packages.avg', 'coupling.ce.avg', 'coupling.ce.max', 'coupling.ce']],
            ['health.coupling', 'project', ['coupling.distance-own.avg', 'coupling.cbo.avg', 'coupling.cbo.p95', 'coupling.cbo.max']],
            ['health.typing', 'class', ['design.type-coverage.all']],
            ['health.typing', 'namespace', ['design.type-coverage.param.total.sum', 'design.type-coverage.return.total.sum', 'design.type-coverage.property.total.sum']],
            ['health.typing', 'project', ['design.type-coverage.param.total.sum', 'design.type-coverage.return.total.sum', 'design.type-coverage.property.total.sum']],
            ['health.maintainability', 'class', ['maintainability.mi.avg', 'maintainability.mi.min']],
            ['health.maintainability', 'namespace', ['maintainability.mi.avg', 'maintainability.mi.p5', 'maintainability.mi.min']],
            ['health.maintainability', 'project', ['maintainability.mi.avg', 'maintainability.mi.p5', 'maintainability.mi.min']],
            ['health.overall', 'class', ['health.complexity', 'health.cohesion', 'health.coupling', 'health.typing']],
            ['health.overall', 'namespace', ['health.complexity', 'health.cohesion', 'health.coupling', 'health.typing', 'health.maintainability']],
            ['health.overall', 'project', ['health.complexity', 'health.cohesion', 'health.coupling', 'health.typing', 'health.maintainability']],
        ] as [$name, $levelName, $keys]) {
            $level = SymbolLevel::from($levelName);
            $policy = $defaults[$name]->getApplicabilityForLevel($level);
            self::assertSame($keys, $policy->keys, $name . ':' . $levelName);
            self::assertTrue($defaults[$name]->isBuiltinFormulaForLevel($level));
            self::assertSame($keys === [], $policy->appliesTo([]));
            $zeros = array_fill_keys($keys, 0);
            self::assertSame($name !== 'health.typing' || $level === SymbolLevel::Class_, $policy->appliesTo($zeros));
            foreach ($keys as $key) {
                foreach ([true, false, '0', '1', \NAN, \INF, -\INF] as $invalid) {
                    try {
                        $policy->appliesTo([...$zeros, $key => $invalid]);
                        self::fail('Invalid supplied policy fact was accepted: ' . $key);
                    } catch (InvalidArgumentException $failure) {
                        self::assertStringContainsString($key, $failure->getMessage());
                    }
                }
                if ($name === 'health.typing' && $level !== SymbolLevel::Class_) {
                    try {
                        $policy->appliesTo([...$zeros, $key => -1]);
                        self::fail('A negative denominator was accepted.');
                    } catch (InvalidArgumentException $failure) {
                        self::assertStringContainsString($key, $failure->getMessage());
                    }
                    self::assertTrue($policy->appliesTo([...$zeros, $key => 1]));
                }
            }
        }
    }

    #[Test]
    public function itSeparatesNamespaceCouplingAbsenceFromMeasuredZeroFacts(): void
    {
        $defaults = ComputedMetricDefaults::getDefaults();
        $evaluation = new ComputedMetricSubjectEvaluation();
        $coupling = $defaults['health.coupling'];
        self::assertSame(ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->evaluate($coupling, SymbolLevel::Namespace_, ['size.symbol-method-count' => 1])->kind);
        self::assertSame(75.0, $evaluation->evaluate($coupling, SymbolLevel::Namespace_, ['coupling.ce' => 0, 'coupling.distance' => 1])->value);
        self::assertSame(100.0, $evaluation->evaluate($coupling, SymbolLevel::Class_, [])->value);
        $invalid = $evaluation->evaluate($coupling, SymbolLevel::Namespace_, ['coupling.ce' => 0, 'coupling.distance' => false]);
        self::assertSame(ComputedMetricOutcome::FAILURE, $invalid->kind);
        self::assertStringContainsString('coupling.distance', $invalid->reason ?? '');
        self::assertSame(80.0, $evaluation->evaluate($defaults['health.overall'], SymbolLevel::Namespace_, ['health.complexity' => 80])->value);
    }

    #[Test]
    public function itUsesPositiveTypingDenominatorsAndMeasuredCohesionHalves(): void
    {
        $evaluation = new ComputedMetricSubjectEvaluation();
        $defaults = ComputedMetricDefaults::getDefaults();
        foreach ([SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
            $typing = $defaults['health.typing'];
            self::assertSame(ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->evaluate($typing, $level, ['design.type-coverage.param.total.sum' => 0])->kind);
            self::assertSame(0.0, $evaluation->evaluate($typing, $level, ['design.type-coverage.param.total.sum' => 1, 'design.type-coverage.param.typed.sum' => 0])->value);
            self::assertSame(ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->evaluate($typing, $level, ['design.type-coverage.param.typed.sum' => 5])->kind);
            self::assertSame(50.0, $evaluation->evaluate($defaults['health.cohesion'], $level, ['cohesion.tcc.count' => 0, 'cohesion.lcom.avg' => 2])->value);
            self::assertSame(0.0, $evaluation->evaluate($defaults['health.cohesion'], $level, ['cohesion.tcc.avg' => 0])->value);
        }
    }

}
