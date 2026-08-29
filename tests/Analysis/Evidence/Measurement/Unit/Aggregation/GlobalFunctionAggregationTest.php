<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\CallableToClassAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\ClassToNamespaceAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceToProjectAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

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
    /**
     * Losing this: the callable level stops reaching global functions, or
     * stops carrying the declaration kind that tells one apart from a method.
     */
    #[Test]
    public function itReachesAGlobalFunctionThroughTheCallableLevelAndKeepsItsDeclarationKind(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function (namespace + member, no type)
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $functionMetrics = (new MetricBag())->with('complexity.ccn', 5);
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/Utils/helpers.php'), 100);

        // Verify it's registered as Function_, not Method
        self::assertSame(SymbolType::Function_, $functionPath->getType());

        $callables = iterator_to_array($repository->all(SymbolLevel::Callable), false);

        self::assertCount(1, $callables);
        self::assertSame(SymbolType::Function_, $callables[0]->symbolPath->getType());
    }

    #[Test]
    public function methodToClassAggregatorSkipsGlobalFunctions(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $functionMetrics = (new MetricBag())->with('complexity.ccn', 5);
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/Utils/helpers.php'), 100);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new CallableToClassAggregator(self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository, $definitions);

        // No class-level metrics should be created for the function
        // (there's no class to aggregate to)
        $classPath = SymbolPath::forClass('App\\Utils', '');
        self::assertSame([], $repository->get($classPath)->all());

        // The function metrics should remain untouched
        $functionBag = $repository->get($functionPath);
        self::assertSame(5, $functionBag->get('complexity.ccn'));
    }

    #[Test]
    public function globalFunctionDoesNotInterfereWithClassAggregation(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function in same namespace
        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $functionMetrics = (new MetricBag())->with('complexity.ccn', 10);
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/Service/helpers.php'), 50);
        // Add a regular class method in same namespace
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'find');
        $methodMetrics = (new MetricBag())->with('complexity.ccn', 3);
        $this->addCallable($repository, $methodPath, $methodMetrics, RelativePath::fromString('src/Service/UserService.php'), 200);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new CallableToClassAggregator(self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository, $definitions);

        // Class aggregation should only include the method, not the function
        $classMetrics = $repository->get(SymbolPath::forClass('App\\Service', 'UserService'));
        self::assertSame(3, (int) $classMetrics->get('complexity.ccn.sum'));
        self::assertSame(1, $classMetrics->get(MetricName::SIZE_SYMBOL_METHOD_COUNT));

        // Function CCN (10) should NOT be mixed into the class
    }

    #[Test]
    public function globalFunctionWithoutNamespaceIsHandledCorrectly(): void
    {
        $repository = new InMemoryMetricRepository();

        // Global function without namespace
        $functionPath = SymbolPath::forGlobalFunction('', 'globalHelper');
        $functionMetrics = (new MetricBag())->with('complexity.ccn', 7);
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/global.php'), 10);

        self::assertSame(SymbolType::Function_, $functionPath->getType());

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);
        $aggregator = new CallableToClassAggregator(self::createStub(ProfilerInterface::class));

        // Should not throw any errors
        $aggregator->aggregate($repository, $definitions);

        // Function metrics should remain intact
        $functionBag = $repository->get($functionPath);
        self::assertSame(7, $functionBag->get('complexity.ccn'));
    }

    #[Test]
    public function functionCcnAggregatesToNamespaceLevel(): void
    {
        $repository = new InMemoryMetricRepository();

        // A standalone function with CCN
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('complexity.ccn', 5), RelativePath::fromString('src/Utils/helpers.php'), 100);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        // Method→Class does nothing for functions (correct behavior)
        $methodToClass = new CallableToClassAggregator(self::createStub(ProfilerInterface::class));
        $methodToClass->aggregate($repository, $definitions);

        // Class→Namespace should pick up the function's CCN
        $classToNamespace = new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class));
        $classToNamespace->aggregate($repository, $definitions);

        $namespaceBag = $repository->get(SymbolPath::forNamespace('App\\Utils'));
        self::assertSame(5, $namespaceBag->get('complexity.ccn.sum'));
        self::assertSame(1, $namespaceBag->get(MetricName::SIZE_SYMBOL_METHOD_COUNT));
    }

    #[Test]
    public function functionCcnAggregatesToProjectLevel(): void
    {
        $repository = new InMemoryMetricRepository();

        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('complexity.ccn', 8), RelativePath::fromString('src/Utils/helpers.php'), 100);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        $methodToClass = new CallableToClassAggregator(self::createStub(ProfilerInterface::class));
        $methodToClass->aggregate($repository, $definitions);

        $classToNamespace = new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class));
        $classToNamespace->aggregate($repository, $definitions);

        $tree = new NamespaceTree($repository->getNamespaces());
        $namespaceToProject = new NamespaceToProjectAggregator($tree, self::createStub(ProfilerInterface::class));
        $namespaceToProject->aggregate($repository, $definitions);

        $projectBag = $repository->get(SymbolPath::forProject());
        self::assertSame(8, $projectBag->get('complexity.ccn.sum'));
        self::assertSame(1, $projectBag->get(MetricName::SIZE_SYMBOL_METHOD_COUNT));
    }

    #[Test]
    public function functionCountedInSymbolMethodCount(): void
    {
        $repository = new InMemoryMetricRepository();

        // A method and a function in the same namespace
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'find');
        $this->addCallable($repository, $methodPath, (new MetricBag())->with('complexity.ccn', 3), RelativePath::fromString('src/Service/UserService.php'), 200);

        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repository->add($classPath, new MetricBag(), RelativePath::fromString('src/Service/UserService.php'), 1);

        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('complexity.ccn', 10), RelativePath::fromString('src/Service/helpers.php'), 50);

        $symbolInfos = $repository->forNamespace('App\\Service');
        $bag = AggregationHelper::addSymbolCounts(new MetricBag(), $symbolInfos);

        // Both the method AND the function should be counted
        self::assertSame(2, $bag->get(MetricName::SIZE_SYMBOL_METHOD_COUNT));
        self::assertSame(1, $bag->get(MetricName::SIZE_SYMBOL_CLASS_COUNT));
    }

    #[Test]
    public function mixedClassAndFunctionNamespaceAggregation(): void
    {
        $repository = new InMemoryMetricRepository();

        // Class method with CCN=3
        $methodPath = SymbolPath::forMethod('App\\Service', 'UserService', 'find');
        $this->addCallable($repository, $methodPath, (new MetricBag())->with('complexity.ccn', 3), RelativePath::fromString('src/Service/UserService.php'), 200);

        // Function with CCN=10
        $functionPath = SymbolPath::forGlobalFunction('App\\Service', 'utility');
        $this->addCallable($repository, $functionPath, (new MetricBag())->with('complexity.ccn', 10), RelativePath::fromString('src/Service/helpers.php'), 50);

        $definitions = AggregationHelper::collectDefinitions([new CyclomaticComplexityCollector()]);

        // Method→Class aggregation: only aggregates the method
        $methodToClass = new CallableToClassAggregator(self::createStub(ProfilerInterface::class));
        $methodToClass->aggregate($repository, $definitions);

        // Class→Namespace: should include class CCN (3 from .sum) + function CCN (10 raw)
        $classToNamespace = new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class));
        $classToNamespace->aggregate($repository, $definitions);

        $namespaceBag = $repository->get(SymbolPath::forNamespace('App\\Service'));
        // class sum (3) + function (10) = 13
        self::assertSame(13, $namespaceBag->get('complexity.ccn.sum'));
        // 1 method + 1 function = 2 callables
        self::assertSame(2, $namespaceBag->get(MetricName::SIZE_SYMBOL_METHOD_COUNT));
    }

    #[Test]
    public function itProjectsDuplicateClassDeclarationsToOneLogicalClassWithoutLosingExactFacts(): void
    {
        $repository = new InMemoryMetricRepository();
        $class = SymbolPath::forClass('App\\Service', 'Duplicate');

        $first = new ClassWithMetrics(
            DeclarationPath::of($class, RelativePath::fromString('src/Service/First.php'), DeclarationOrdinal::fromRank(0)),
            10,
            3,
            MetricBag::fromArray(['firstProviderMetric' => 7]),
        );
        $second = new ClassWithMetrics(
            DeclarationPath::of($class, RelativePath::fromString('src/Service/Second.php'), DeclarationOrdinal::fromRank(0)),
            20,
            5,
            MetricBag::fromArray(['secondProviderMetric' => 11]),
        );

        $repository->addSubject($first->subject, $first->metrics, $first->declarationPath->file, $first->line);
        $repository->addSubject($second->subject, $second->metrics, $second->declarationPath->file, $second->line);

        self::assertSame(7, $repository->getSubject(MetricSubject::declaration($first->declarationPath))->get('firstProviderMetric'));
        self::assertSame(11, $repository->getSubject(MetricSubject::declaration($second->declarationPath))->get('secondProviderMetric'));
        self::assertCount(2, iterator_to_array($repository->allDeclarations(), false));

        $logicalClasses = iterator_to_array($repository->allLogicalClasses(), false);
        self::assertCount(1, $logicalClasses);
        self::assertSame($class->toCanonical(), $logicalClasses[0]->symbolPath->toCanonical());
        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::Class_), false));

        $definitions = [
            new MetricDefinition('firstProviderMetric', SymbolLevel::Class_, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            ]),
            new MetricDefinition('secondProviderMetric', SymbolLevel::Class_, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            ]),
        ];
        (new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class)))->aggregate($repository, $definitions);

        $namespace = $repository->get(SymbolPath::forNamespace('App\\Service'));
        self::assertSame(7, $namespace->get('firstProviderMetric.sum'));
        self::assertSame(11, $namespace->get('secondProviderMetric.sum'));
        self::assertSame(1, $namespace->get(MetricName::SIZE_SYMBOL_CLASS_COUNT));
    }

    private function addCallable(InMemoryMetricRepository $repository, SymbolPath $symbol, MetricBag $metrics, RelativePath $file, int $startFilePos): void
    {
        $owner = $symbol->getType() === SymbolType::Method ? new LogicalClassPath(SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? '')) : null;
        $repository->addCallable(new CallableWithMetrics(DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0)), $startFilePos, $symbol->getType() === SymbolType::Method ? CallableKind::Method : CallableKind::Function, null, null, $owner, $metrics));
    }
}
