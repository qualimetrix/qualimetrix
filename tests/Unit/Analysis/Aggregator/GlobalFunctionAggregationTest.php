<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\AggregationHelper;
use Qualimetrix\Analysis\Aggregator\ClassToNamespaceAggregator;
use Qualimetrix\Analysis\Aggregator\MethodToClassAggregator;
use Qualimetrix\Analysis\Aggregator\NamespaceToProjectAggregator;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationMeta;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;

/**
 * Tests that global functions (not in any class) are handled correctly
 * during method-to-class aggregation.
 *
 * Global functions are represented by SymbolPath with namespace + member
 * but no type (class). They have SymbolType::Function_ and should be
 * skipped during method-to-class aggregation since they don't belong to
 * any class.
 */
#[CoversClass(MethodToClassAggregator::class)]
#[CoversClass(ClassToNamespaceAggregator::class)]
#[CoversClass(NamespaceToProjectAggregator::class)]
#[CoversClass(AggregationHelper::class)]
final class GlobalFunctionAggregationTest extends TestCase
{
    #[Test]
    public function globalFunctionIsNotIteratedByMethodQuery(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function (namespace + member, no type)
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $functionMetrics = (new MetricBag())->with('ccn', 5);
        $repository->add($functionPath, $functionMetrics, 'src/Utils/helpers.php', 10);

        // Verify it's registered as Function_, not Method
        self::assertSame(SymbolType::Function_, $functionPath->getType());

        // all(SymbolType::Method) should NOT return functions
        $methods = iterator_to_array($repository->all(SymbolType::Method));
        self::assertCount(0, $methods);

        // all(SymbolType::Function_) should return it
        $functions = iterator_to_array($repository->all(SymbolType::Function_));
        self::assertCount(1, $functions);
    }

    #[Test]
    public function methodToClassAggregatorSkipsGlobalFunctions(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $functionMetrics = (new MetricBag())->with('ccn', 5);
        $repository->add($functionPath, $functionMetrics, 'src/Utils/helpers.php', 10);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new MethodToClassAggregator();
        $aggregator->aggregate($repository, $definitions);

        // No class-level metrics should be created for the function
        // (there's no class to aggregate to)
        $classPath = SymbolPath::forClass('App\\Utils', '');
        self::assertSame([], $repository->get($classPath)->all());

        // The function metrics should remain untouched
        $functionBag = $repository->get($functionPath);
        self::assertSame(5, $functionBag->get('ccn'));
    }

    #[Test]
    public function globalFunctionDoesNotInterfereWithClassAggregation(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function in same namespace
        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $functionMetrics = (new MetricBag())->with('ccn', 10);
        $repository->add($functionPath, $functionMetrics, 'src/Service/helpers.php', 5);

        // Add a regular class method in same namespace
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'find');
        $methodMetrics = (new MetricBag())->with('ccn', 3);
        $repository->add($methodPath, $methodMetrics, 'src/Service/UserService.php', 20);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new MethodToClassAggregator();
        $aggregator->aggregate($repository, $definitions);

        // Class aggregation should only include the method, not the function
        $classMetrics = $repository->get(SymbolPath::forClass('App\\Service', 'UserService'));
        self::assertSame(3, (int) $classMetrics->get('ccn.sum'));
        self::assertSame(1, $classMetrics->get(AggregationMeta::SYMBOL_METHOD_COUNT));

        // Function CCN (10) should NOT be mixed into the class
    }

    #[Test]
    public function globalFunctionWithoutNamespaceIsHandledCorrectly(): void
    {
        $repository = new InMemoryMetricRepository();

        // Global function without namespace
        $functionPath = SymbolPath::forGlobalFunction('', 'globalHelper');
        $functionMetrics = (new MetricBag())->with('ccn', 7);
        $repository->add($functionPath, $functionMetrics, 'src/global.php', 1);

        self::assertSame(SymbolType::Function_, $functionPath->getType());

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new MethodToClassAggregator();

        // Should not throw any errors
        $aggregator->aggregate($repository, $definitions);

        // Function metrics should remain intact
        $functionBag = $repository->get($functionPath);
        self::assertSame(7, $functionBag->get('ccn'));
    }

    #[Test]
    public function functionCcnAggregatesToNamespaceLevel(): void
    {
        $repository = new InMemoryMetricRepository();

        // A standalone function with CCN
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $repository->add($functionPath, (new MetricBag())->with('ccn', 5), 'src/Utils/helpers.php', 10);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        // Method→Class does nothing for functions (correct behavior)
        $methodToClass = new MethodToClassAggregator();
        $methodToClass->aggregate($repository, $definitions);

        // Class→Namespace should pick up the function's CCN
        $classToNamespace = new ClassToNamespaceAggregator();
        $classToNamespace->aggregate($repository, $definitions);

        $namespaceBag = $repository->get(SymbolPath::forNamespace('App\\Utils'));
        self::assertSame(5, $namespaceBag->get('ccn.sum'));
        self::assertSame(1, $namespaceBag->get(AggregationMeta::SYMBOL_METHOD_COUNT));
    }

    #[Test]
    public function functionCcnAggregatesToProjectLevel(): void
    {
        $repository = new InMemoryMetricRepository();

        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $repository->add($functionPath, (new MetricBag())->with('ccn', 8), 'src/Utils/helpers.php', 10);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        $methodToClass = new MethodToClassAggregator();
        $methodToClass->aggregate($repository, $definitions);

        $classToNamespace = new ClassToNamespaceAggregator();
        $classToNamespace->aggregate($repository, $definitions);

        $tree = new NamespaceTree($repository->getNamespaces());
        $namespaceToProject = new NamespaceToProjectAggregator($tree);
        $namespaceToProject->aggregate($repository, $definitions);

        $projectBag = $repository->get(SymbolPath::forProject());
        self::assertSame(8, $projectBag->get('ccn.sum'));
        self::assertSame(1, $projectBag->get(AggregationMeta::SYMBOL_METHOD_COUNT));
    }

    #[Test]
    public function functionCountedInSymbolMethodCount(): void
    {
        $repository = new InMemoryMetricRepository();

        // A method and a function in the same namespace
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'find');
        $repository->add($methodPath, (new MetricBag())->with('ccn', 3), 'src/Service/UserService.php', 20);

        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($classPath, new MetricBag(), 'src/Service/UserService.php', 1);

        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $repository->add($functionPath, (new MetricBag())->with('ccn', 10), 'src/Service/helpers.php', 5);

        $symbolInfos = $repository->forNamespace('App\\Service');
        $bag = AggregationHelper::addSymbolCounts(new MetricBag(), $symbolInfos);

        // Both the method AND the function should be counted
        self::assertSame(2, $bag->get(AggregationMeta::SYMBOL_METHOD_COUNT));
        self::assertSame(1, $bag->get(AggregationMeta::SYMBOL_CLASS_COUNT));
    }

    #[Test]
    public function mixedClassAndFunctionNamespaceAggregation(): void
    {
        $repository = new InMemoryMetricRepository();

        // Class method with CCN=3
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'find');
        $repository->add($methodPath, (new MetricBag())->with('ccn', 3), 'src/Service/UserService.php', 20);

        // Function with CCN=10
        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $repository->add($functionPath, (new MetricBag())->with('ccn', 10), 'src/Service/helpers.php', 5);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        // Method→Class aggregation: only aggregates the method
        $methodToClass = new MethodToClassAggregator();
        $methodToClass->aggregate($repository, $definitions);

        // Class→Namespace: should include class CCN (3 from .sum) + function CCN (10 raw)
        $classToNamespace = new ClassToNamespaceAggregator();
        $classToNamespace->aggregate($repository, $definitions);

        $namespaceBag = $repository->get(SymbolPath::forNamespace('App\\Service'));
        // class sum (3) + function (10) = 13
        self::assertSame(13, $namespaceBag->get('ccn.sum'));
        // 1 method + 1 function = 2 callables
        self::assertSame(2, $namespaceBag->get(AggregationMeta::SYMBOL_METHOD_COUNT));
    }
}
