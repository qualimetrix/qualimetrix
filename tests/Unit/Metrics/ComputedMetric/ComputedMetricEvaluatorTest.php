<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\ComputedMetric;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefaults;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Metrics\ComputedMetric\ComputedMetricEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComputedMetricEvaluator::class)]
final class ComputedMetricEvaluatorTest extends TestCase
{
    private ComputedMetricEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ComputedMetricEvaluator();
    }

    #[Test]
    public function emptyDefinitionsIsNoOp(): void
    {
        $repo = new InMemoryMetricRepository();
        $this->evaluator->compute($repo, []);

        self::assertSame([], $repo->get(SymbolPath::forProject())->all());
    }

    #[Test]
    public function simpleFormulaEvaluation(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'ccn.avg' => 3.0,
        ]), 'src/UserService.php', 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'ccn__avg * 10'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        $this->evaluator->compute($repo, [$definition]);

        $result = $repo->get($classPath)->get('health.test');
        self::assertSame(30.0, $result);
    }

    #[Test]
    public function topologicalOrderingDependentMetrics(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'ccn.avg' => 5.0,
        ]), 'src/UserService.php', 10);

        // Define B first, which depends on A — evaluator should sort them
        $defB = new ComputedMetricDefinition(
            name: 'health.b',
            formulas: ['class' => 'health__a * 2'],
            description: 'Depends on A',
            levels: [SymbolType::Class_],
        );
        $defA = new ComputedMetricDefinition(
            name: 'health.a',
            formulas: ['class' => 'ccn__avg + 1'],
            description: 'Base metric',
            levels: [SymbolType::Class_],
        );

        // Pass B before A — topological sort should fix the order
        $this->evaluator->compute($repo, [$defB, $defA]);

        $bag = $repo->get($classPath);
        self::assertSame(6.0, $bag->get('health.a'));
        self::assertSame(12.0, $bag->get('health.b'));
    }

    #[Test]
    public function missingVariableWithoutFallbackDoesNotCrash(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([]), 'src/UserService.php', 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'missing_var * 10'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
        );

        $this->evaluator->compute($repo, [$definition]);

        // Metric should not be computed
        self::assertNull($repo->get($classPath)->get('health.test'));
    }

    #[Test]
    public function missingVariableWithNullCoalescingFallback(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([]), 'src/UserService.php', 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => '(missing_var ?? 42) * 2'],
            description: 'Test metric with fallback',
            levels: [SymbolType::Class_],
        );

        $this->evaluator->compute($repo, [$definition]);

        self::assertSame(84.0, $repo->get($classPath)->get('health.test'));
    }

    #[Test]
    public function nanResultIsNotStored(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'value' => -1.0,
        ]), 'src/UserService.php', 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'sqrt(value)'],
            description: 'NaN test',
            levels: [SymbolType::Class_],
        );

        $this->evaluator->compute($repo, [$definition]);

        self::assertNull($repo->get($classPath)->get('health.test'));
    }

    #[Test]
    public function infinityResultIsNotStored(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'value' => 0.0,
        ]), 'src/UserService.php', 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'log(value)'],
            description: 'Infinity test',
            levels: [SymbolType::Class_],
        );

        $this->evaluator->compute($repo, [$definition]);

        self::assertNull($repo->get($classPath)->get('health.test'));
    }

    #[Test]
    public function defaultHealthFormulasAtClassLevel(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'ccn.avg' => 4.0,
            'cognitive.avg' => 6.0,
            'npath.avg' => 10.0,
            'tcc' => 0.6,
            'lcom' => 2.0,
            'cbo' => 8.0,
            'ce' => 6.0,
            'typeCoverage.pct' => 80.0,
            'dit' => 2.0,
            'mi.avg' => 65.0,
        ]), 'src/UserService.php', 10);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluator->compute($repo, $defaults);

        $bag = $repo->get($classPath);

        // health.complexity = clamp(100 - max(4-4,0)*2.0 - max(6-5,0)*2.5 - min(10/20,20)*0.5, 0, 100)
        //                   = 100 - 0 - 2.5 - 0.25 = 97.25
        self::assertEqualsWithDelta(97.25, $bag->get('health.complexity'), 0.01);

        // health.cohesion = clamp(0.6*50 + (1 - clamp((2-1)/5, 0, 1)) * 50, 0, 100) = 30 + 0.8*50 = 70
        self::assertEqualsWithDelta(70.0, $bag->get('health.cohesion'), 0.01);

        // health.coupling = clamp(100 * 15 / (15 + max(6-5, 0)), 0, 100) = 1500/16 = 93.75
        self::assertEqualsWithDelta(93.75, $bag->get('health.coupling'), 0.01);

        // health.typing = clamp(80, 0, 100) = 80
        self::assertEqualsWithDelta(80.0, $bag->get('health.typing'), 0.01);

        // health.maintainability = clamp(65, 0, 100) = 65
        self::assertEqualsWithDelta(65.0, $bag->get('health.maintainability'), 0.01);

        // health.overall = clamp(97.25*0.30 + 70*0.25 + 93.75*0.25 + 80*0.20, 0, 100)
        //                = 29.175 + 17.5 + 23.4375 + 16.0 = 86.11
        self::assertEqualsWithDelta(86.11, $bag->get('health.overall'), 0.01);
    }

    #[Test]
    public function defaultHealthFormulasAtNamespaceLevel(): void
    {
        $repo = new InMemoryMetricRepository();

        // Add a class so the namespace is registered
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([]), 'src/UserService.php', 10);

        // Add namespace-level metrics
        $nsPath = SymbolPath::forNamespace('App\\Service');
        $repo->add($nsPath, MetricBag::fromArray([
            'ccn.avg' => 3.0,
            'ccn.sum' => 30.0,
            'cognitive.avg' => 4.0,
            'cognitive.sum' => 40.0,
            'symbolMethodCount' => 10,
            'npath.avg' => 5.0,
            'tcc.avg' => 0.5,
            'lcom.avg' => 3.0,
            'cbo.avg' => 6.0,
            'distance' => 0.3,
            'typeCoverage.paramTyped.sum' => 40.0,
            'typeCoverage.returnTyped.sum' => 35.0,
            'typeCoverage.propertyTyped.sum' => 20.0,
            'typeCoverage.paramTotal.sum' => 50.0,
            'typeCoverage.returnTotal.sum' => 50.0,
            'typeCoverage.propertyTotal.sum' => 25.0,
            'dit.avg' => 1.5,
            'abstractness' => 0.2,
            'mi.avg' => 70.0,
        ]), '', null);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluator->compute($repo, $defaults);

        $bag = $repo->get($nsPath);

        // health.complexity = clamp(100 - max(30/10 - 4, 0)*2.0 - max(40/10 - 5, 0)*2.5 - min(5/20,20)*0.5, 0, 100)
        //                   = 100 - 0 - 0 - 0.125 = 99.875
        self::assertEqualsWithDelta(99.88, $bag->get('health.complexity'), 0.01);

        // health.cohesion = clamp(0.5*50 + (1 - clamp((3-1)/5, 0, 1))*50, 0, 100) = 25 + 0.6*50 = 55
        self::assertEqualsWithDelta(55.0, $bag->get('health.cohesion'), 0.01);

        // health.coupling = 100 * 12 / (12 + 0.3*6.5 + max(6-7,0)*4) = 1200 / 13.95 ≈ 86.02
        self::assertEqualsWithDelta(86.02, $bag->get('health.coupling'), 0.01);

        // health.typing = (40+35+20) / max(50+50+25, 1) * 100 = 95/125 * 100 = 76
        self::assertEqualsWithDelta(76.0, $bag->get('health.typing'), 0.01);

        // health.maintainability = clamp(70, 0, 100) = 70
        self::assertEqualsWithDelta(70.0, $bag->get('health.maintainability'), 0.01);

        // health.overall = clamp(99.88*0.25 + 55*0.20 + 86.02*0.20 + 76*0.15 + 70*0.20, 0, 100)
        //                = 24.97 + 11.0 + 17.20 + 11.4 + 14.0 = 78.57
        self::assertEqualsWithDelta(78.57, $bag->get('health.overall'), 0.01);
    }

    #[Test]
    public function mathFunctionsWork(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'Svc');
        $repo->add($classPath, MetricBag::fromArray([
            'a' => 16.0,
            'b' => -5.0,
            'c' => 3.0,
            'd' => 7.0,
            'e' => 100.0,
            'f' => 1000.0,
        ]), 'src/Svc.php', 1);

        $tests = [
            ['health.sqrtTest', 'sqrt(a)', 4.0],
            ['health.absTest', 'abs(b)', 5.0],
            ['health.minTest', 'min(c, d)', 3.0],
            ['health.maxTest', 'max(c, d)', 7.0],
            ['health.logTest', 'log(e)', log(100.0)],
            ['health.log10Test', 'log10(f)', 3.0],
            ['health.clampTest', 'clamp(150, 0, 100)', 100.0],
            ['health.clampLowTest', 'clamp(b, 0, 100)', 0.0],
        ];

        $definitions = [];
        foreach ($tests as [$name, $formula]) {
            $definitions[] = new ComputedMetricDefinition(
                name: $name,
                formulas: ['class' => $formula],
                description: 'Math test',
                levels: [SymbolType::Class_],
            );
        }

        $this->evaluator->compute($repo, $definitions);

        $bag = $repo->get($classPath);
        foreach ($tests as [$name, , $expected]) {
            self::assertEqualsWithDelta($expected, $bag->get($name), 0.001, "Failed for {$name}");
        }
    }

    #[Test]
    public function multipleClassesEachGetOwnMetric(): void
    {
        $repo = new InMemoryMetricRepository();

        $class1 = SymbolPath::forClass('App', 'ClassA');
        $class2 = SymbolPath::forClass('App', 'ClassB');

        $repo->add($class1, MetricBag::fromArray(['ccn' => 2.0]), 'src/ClassA.php', 1);
        $repo->add($class2, MetricBag::fromArray(['ccn' => 8.0]), 'src/ClassB.php', 1);

        $definition = new ComputedMetricDefinition(
            name: 'health.simple',
            formulas: ['class' => 'ccn * 10'],
            description: 'Simple test',
            levels: [SymbolType::Class_],
        );

        $this->evaluator->compute($repo, [$definition]);

        self::assertSame(20.0, $repo->get($class1)->get('health.simple'));
        self::assertSame(80.0, $repo->get($class2)->get('health.simple'));
    }

    #[Test]
    public function projectLevelInheritsNamespaceFormula(): void
    {
        $repo = new InMemoryMetricRepository();

        // Need a class to register the namespace
        $classPath = SymbolPath::forClass('App', 'Svc');
        $repo->add($classPath, MetricBag::fromArray([]), 'src/Svc.php', 1);

        $nsPath = SymbolPath::forNamespace('App');
        $repo->add($nsPath, MetricBag::fromArray(['value' => 42.0]), '', null);

        $projectPath = SymbolPath::forProject();
        $repo->add($projectPath, MetricBag::fromArray(['value' => 99.0]), '', null);

        $definition = new ComputedMetricDefinition(
            name: 'health.inherited',
            formulas: ['namespace' => 'value + 1'],
            description: 'Inherits namespace formula for project',
            levels: [SymbolType::Namespace_, SymbolType::Project],
        );

        $this->evaluator->compute($repo, [$definition]);

        self::assertSame(43.0, $repo->get($nsPath)->get('health.inherited'));
        // Project should use the namespace formula with project-level metrics
        self::assertSame(100.0, $repo->get($projectPath)->get('health.inherited'));
    }

    #[Test]
    public function circularDependencyDoesNotCrash(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App', 'Svc');
        $repo->add($classPath, MetricBag::fromArray(['x' => 1.0]), 'src/Svc.php', 1);

        $defA = new ComputedMetricDefinition(
            name: 'health.a',
            formulas: ['class' => '(health__b ?? 0) + x'],
            description: 'Circular A',
            levels: [SymbolType::Class_],
        );
        $defB = new ComputedMetricDefinition(
            name: 'health.b',
            formulas: ['class' => '(health__a ?? 0) + x'],
            description: 'Circular B',
            levels: [SymbolType::Class_],
        );

        // Should not throw — falls back to original order with warning
        $this->evaluator->compute($repo, [$defA, $defB]);

        // Both should compute using the fallback ?? 0
        $bag = $repo->get($classPath);
        self::assertNotNull($bag->get('health.a'));
        self::assertNotNull($bag->get('health.b'));
    }

    #[Test]
    public function complexityHealthUsesPerMethodAverageAtNamespaceLevel(): void
    {
        $repo = new InMemoryMetricRepository();

        // Namespace with 2 classes: one has 5 methods (all CCN=2), another has 1 method (CCN=30)
        // WMC class A = 10 (ccn.sum), WMC class B = 30 (ccn.sum)
        // Per-class CCN avg (old formula) = avg(10, 30) = 20 → terrible score
        // Per-method CCN avg (new formula) = (10+30)/6 = 6.67 → moderate

        $classA = SymbolPath::forClass('App\\Service', 'ClassA');
        $repo->add($classA, MetricBag::fromArray([]), 'src/ClassA.php', 1);

        $nsPath = SymbolPath::forNamespace('App\\Service');
        $repo->add($nsPath, MetricBag::fromArray([
            'ccn.sum' => 40.0,          // total CCN across all methods
            'ccn.avg' => 20.0,          // average WMC (per-class) - NOT per-method
            'cognitive.sum' => 30.0,
            'cognitive.avg' => 15.0,    // average per-class cognitive sum
            'symbolMethodCount' => 6,    // total method count
            'npath.avg' => 10.0,
        ]), '', null);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());

        // Only evaluate health.complexity
        $complexityDef = array_filter($defaults, static fn($d) => $d->name === 'health.complexity');
        $this->evaluator->compute($repo, array_values($complexityDef));

        $bag = $repo->get($nsPath);
        $score = $bag->get('health.complexity');
        self::assertNotNull($score);

        // Per-method averages: CCN = 40/6 ≈ 6.67, Cognitive = 30/6 = 5.0
        // penalty = max(6.67-4, 0)*2.0 + max(5.0-5, 0)*2.5 + min(10/20, 20)*0.5
        //         = 2.67*2.0 + 0 + 0.5*0.5 = 5.34 + 0.25 = 5.59
        // score = 100 - 5.59 = 94.41
        self::assertEqualsWithDelta(94.41, $score, 0.01);

        // Verify: with old formula (ccn.avg=20), score would be much worse:
        // penalty = max(20-4, 0)*2.0 + max(15-5, 0)*2.5 + 0.25 = 32 + 25 + 0.25 = 57.25
        // score = 100 - 57.25 = 42.75 (much lower!)
        self::assertGreaterThan(80.0, $score, 'Per-method averaging should give a much better score than per-class WMC averaging');
    }
}
