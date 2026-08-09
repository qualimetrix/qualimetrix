<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\AggregationHelper;
use Qualimetrix\Analysis\Aggregator\CallableToClassAggregator;
use Qualimetrix\Analysis\Aggregator\ClassToNamespaceAggregator;
use Qualimetrix\Analysis\Aggregator\NamespaceToProjectAggregator;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationMeta;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
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
#[CoversClass(CallableToClassAggregator::class)]
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
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/Utils/helpers.php'), 100);

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
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/Utils/helpers.php'), 100);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new CallableToClassAggregator();
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
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/Service/helpers.php'), 50);
        // Add a regular class method in same namespace
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'find');
        $methodMetrics = (new MetricBag())->with('ccn', 3);
        $this->addCallable($repository, $methodPath, $methodMetrics, RelativePath::fromString('src/Service/UserService.php'), 200);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new CallableToClassAggregator();
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
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/global.php'), 10);

        self::assertSame(SymbolType::Function_, $functionPath->getType());

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new CallableToClassAggregator();

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
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('ccn', 5), RelativePath::fromString('src/Utils/helpers.php'), 100);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        // Method→Class does nothing for functions (correct behavior)
        $methodToClass = new CallableToClassAggregator();
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
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('ccn', 8), RelativePath::fromString('src/Utils/helpers.php'), 100);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        $methodToClass = new CallableToClassAggregator();
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
        $this->addCallable($repository, $methodPath, (new MetricBag())->with('ccn', 3), RelativePath::fromString('src/Service/UserService.php'), 200);

        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($classPath, new MetricBag(), RelativePath::fromString('src/Service/UserService.php'), 1);

        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('ccn', 10), RelativePath::fromString('src/Service/helpers.php'), 50);

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
        $this->addCallable($repository, $methodPath, (new MetricBag())->with('ccn', 3), RelativePath::fromString('src/Service/UserService.php'), 200);

        // Function with CCN=10
        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('ccn', 10), RelativePath::fromString('src/Service/helpers.php'), 50);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        // Method→Class aggregation: only aggregates the method
        $methodToClass = new CallableToClassAggregator();
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
    private function addCallable(InMemoryMetricRepository $repository, SymbolPath $symbol, MetricBag $metrics, RelativePath $file, int $startFilePos): void
    {
        $owner = $symbol->getType() === SymbolType::Method ? new LogicalClassPath(SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? '')) : null;
        $repository->addCallable(new CallableWithMetrics(new DeclarationPath($symbol, $file, $startFilePos), $symbol->getType() === SymbolType::Method ? CallableKind::Method : CallableKind::Function, null, null, $owner, $metrics));
    }
}
