<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\AggregationHelper;
use AiMessDetector\Analysis\Aggregator\MethodToClassAggregator;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        self::assertSame(1, $classMetrics->get('symbolMethodCount'));

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
}
