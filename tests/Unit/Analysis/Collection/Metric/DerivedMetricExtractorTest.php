<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Metric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\CallableToClassAggregator;
use Qualimetrix\Analysis\Aggregator\ClassToNamespaceAggregator;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Collection\Metric\DerivedMetricExtractor;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DerivedMetricExtractor::class)]
final class DerivedMetricExtractorTest extends TestCase
{
    #[Test]
    public function itExtractsDerivedMetricsForExistingMethods(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'calculate');
        $callable = $this->callable($methodSymbol, MetricBag::fromArray(['ccn' => 5]));
        $repository->addCallable($callable);

        $fileBag = MetricBag::fromArray([
            $this->derivedKey('mi', $callable) => 85.5,
        ]);

        $extractor->extract($repository, $fileBag, [$callable], RelativePath::fromString('tmp/test.php'));

        self::assertTrue($repository->has($methodSymbol));
        $methodBag = $repository->get($methodSymbol);
        self::assertSame(85.5, $methodBag->get('mi'));
        // Original metric should still be there
        self::assertSame(5, $methodBag->get('ccn'));
    }

    #[Test]
    public function itUsesCallableSourceLineWhenAddingDerivedMetricsToAPlainExactSubject(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $extractor = new DerivedMetricExtractor(new CompositeCollector([], [$derivedCollector]));

        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $file = RelativePath::fromString('src/Service.php');
        $callable = new CallableWithMetrics(
            new DeclarationPath($symbol, $file, 701),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
            MetricBag::fromArray(['ccn' => 3]),
            23,
        );
        $subject = MetricSubject::declaration($callable->declarationPath);
        $repository->addSubject($subject, MetricBag::fromArray(['ccn' => 3]), $file, null);

        $extractor->extract(
            $repository,
            MetricBag::fromArray([$this->derivedKey('mi', $callable) => 80.0]),
            [$callable],
            $file,
        );

        $declarations = iterator_to_array($repository->allDeclarations(), false);
        self::assertCount(1, $declarations);
        self::assertSame(23, $declarations[0]->line);
        self::assertNotSame($callable->declarationPath->startFilePos, $declarations[0]->line);
        self::assertSame(80.0, $repository->getSubject($subject)->get('mi'));
    }

    #[Test]
    public function itIgnoresInvalidFqns(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();

        $fileBag = MetricBag::fromArray([
            'mi:InvalidFqn' => 85.5,         // no ::
            'mi:123Invalid::method' => 90.0,  // starts with digit
            'mi:' => 80.0,                    // empty FQN
            'mi:::double' => 75.0,            // invalid format
        ]);

        // Should not throw exceptions
        $extractor->extract($repository, $fileBag, [], RelativePath::fromString('tmp/test.php'));

        // No symbols should be created
        self::assertFalse($repository->has(SymbolPath::forMethod('', 'InvalidFqn', '')));
    }

    #[Test]
    public function itIgnoresNonDerivedMetrics(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'method');
        $callable = $this->callable($methodSymbol, new MetricBag());
        $repository->addCallable($callable);

        $fileBag = MetricBag::fromArray([
            'ccn:App\Service::method' => 5,   // not a derived metric
            'loc:App\Service::method' => 20,   // not a derived metric
            $this->derivedKey('mi', $callable) => 85.5,
        ]);

        $extractor->extract($repository, $fileBag, [$callable], RelativePath::fromString('tmp/test.php'));

        $methodBag = $repository->get($methodSymbol);
        self::assertTrue($methodBag->has('mi'));
        self::assertFalse($methodBag->has('ccn'));
        self::assertFalse($methodBag->has('loc'));
    }

    #[Test]
    public function itIsNoopWhenNoDerivedCollectors(): void
    {
        $compositeCollector = new CompositeCollector([]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'method');
        $callable = $this->callable($methodSymbol, MetricBag::fromArray(['ccn' => 5]));
        $repository->addCallable($callable);

        $fileBag = MetricBag::fromArray([
            'ccn:App\Service::method' => 5,
        ]);

        $extractor->extract($repository, $fileBag, [$callable], RelativePath::fromString('tmp/test.php'));

        $methodBag = $repository->get($methodSymbol);
        // Original metrics untouched, no derived metrics added
        self::assertSame(5, $methodBag->get('ccn'));
    }

    #[Test]
    public function itIgnoresDerivedMetricsForNonExistentMethods(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();

        $fileBag = MetricBag::fromArray([
            'mi:App\NonExistent::method' => 85.5,
        ]);

        $extractor->extract($repository, $fileBag, [], RelativePath::fromString('tmp/test.php'));

        $nonExistentSymbol = SymbolPath::forMethod('App', 'NonExistent', 'method');
        self::assertFalse($repository->has($nonExistentSymbol));
    }

    #[Test]
    public function itHandlesFqnWithoutNamespace(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();
        $methodSymbol = SymbolPath::forMethod('', 'SimpleClass', 'method');
        $callable = $this->callable($methodSymbol, MetricBag::fromArray(['ccn' => 3]));
        $repository->addCallable($callable);

        $fileBag = MetricBag::fromArray([
            $this->derivedKey('mi', $callable) => 85.5,
        ]);

        $extractor->extract($repository, $fileBag, [$callable], RelativePath::fromString('tmp/test.php'));

        self::assertTrue($repository->has($methodSymbol));
        self::assertSame(85.5, $repository->get($methodSymbol)->get('mi'));
    }

    #[Test]
    public function itIgnoresMetricsWithoutColonSeparator(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();

        $fileBag = MetricBag::fromArray([
            'totalLoc' => 100,
            'fileComplexity' => 50,
        ]);

        // Should not throw exceptions
        $extractor->extract($repository, $fileBag, [], RelativePath::fromString('tmp/test.php'));

        // No method symbols should have been created
        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function itResolvesDerivedMetricsForStandaloneFunctions(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();
        // Register a function (not a class) in the repository
        $functionSymbol = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $callable = $this->callable($functionSymbol, MetricBag::fromArray(['ccn' => 5]));
        $repository->addCallable($callable);

        // Derived collector outputs MI keyed by FQN — same format as class FQN
        $fileBag = MetricBag::fromArray([
            $this->derivedKey('mi', $callable) => 72.5,
        ]);

        $extractor->extract($repository, $fileBag, [$callable], RelativePath::fromString('tmp/test.php'));

        // MI should be resolved to the function, not silently discarded
        self::assertTrue($repository->has($functionSymbol));
        $bag = $repository->get($functionSymbol);
        self::assertSame(72.5, $bag->get('mi'));
        self::assertSame(5, $bag->get('ccn'));
    }

    #[Test]
    public function itPreservesFunctionDerivedMetricsWithoutClassFqnFallback(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();
        // Both a class and a function with same short name
        $classSymbol = SymbolPath::forClass('App\\Utils', 'helper');
        $repository->add($classSymbol, MetricBag::fromArray(['tcc' => 0.5]), RelativePath::fromString('tmp/test.php'), 1);

        $functionSymbol = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $callable = $this->callable($functionSymbol, MetricBag::fromArray(['ccn' => 3]));
        $repository->addCallable($callable);

        $fileBag = MetricBag::fromArray([
            $this->derivedKey('mi', $callable) => 80.0,
        ]);

        $extractor->extract($repository, $fileBag, [$callable], RelativePath::fromString('tmp/test.php'));

        self::assertNull($repository->get($classSymbol)->get('mi'));
        self::assertSame(80.0, $repository->get($functionSymbol)->get('mi'));
    }

    #[Test]
    public function itKeepsDerivedMetricsForDuplicateCallableDeclarationsThroughClassAndNamespaceAggregation(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $extractor = new DerivedMetricExtractor(new CompositeCollector([], [$derivedCollector]));

        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $first = new CallableWithMetrics(
            new DeclarationPath($symbol, RelativePath::fromString('src/First.php'), 410),
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['ccn' => 3]),
            17,
        );
        $second = new CallableWithMetrics(
            new DeclarationPath($symbol, RelativePath::fromString('src/Second.php'), 920),
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['ccn' => 5]),
            31,
        );
        $repository->addCallable($first);
        $repository->addCallable($second);

        $extractor->extract(
            $repository,
            MetricBag::fromArray([$this->derivedKey('mi', $first) => 80.0]),
            [$first],
            $first->declarationPath->file,
        );
        $extractor->extract(
            $repository,
            MetricBag::fromArray([$this->derivedKey('mi', $second) => 60.0]),
            [$second],
            $second->declarationPath->file,
        );

        self::assertSame(80.0, $repository->getSubject($this->declarationSubject($first))->get('mi'));
        self::assertSame(60.0, $repository->getSubject($this->declarationSubject($second))->get('mi'));
        $callables = iterator_to_array($repository->allCallables(), false);
        self::assertCount(2, $callables);
        foreach ($callables as $callable) {
            self::assertSame(CallableKind::Method, $callable->callableKind);
        }

        $definition = new MetricDefinition('mi', SymbolLevel::Callable, [
            SymbolLevel::Class_->value => [AggregationStrategy::Average],
            SymbolLevel::Namespace_->value => [AggregationStrategy::Average],
        ]);
        (new CallableToClassAggregator())->aggregate($repository, [$definition]);
        (new ClassToNamespaceAggregator())->aggregate($repository, [$definition]);

        self::assertSame(70.0, $repository->get(SymbolPath::forClass('App', 'Service'))->get('mi.avg'));
        $namespace = $repository->get(SymbolPath::forNamespace('App'));
        self::assertSame(70.0, $namespace->get('mi.avg'));
        self::assertSame(2, $namespace->get('mi.count'));
    }

    private function callable(SymbolPath $symbol, MetricBag $metrics): CallableWithMetrics
    {
        $file = RelativePath::fromString('tmp/test.php');

        if ($symbol->getType()->value === 'function') {
            return new CallableWithMetrics(
                new DeclarationPath($symbol, $file, 0),
                CallableKind::Function,
                null,
                null,
                null,
                $metrics,
            );
        }

        return new CallableWithMetrics(
            new DeclarationPath($symbol, $file, 0),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? '')),
            $metrics,
        );
    }

    private function derivedKey(string $metric, CallableWithMetrics $callable): string
    {
        return $metric . ':' . $callable->kind->value . ':' . $callable->declarationPath->toCanonical();
    }

    private function declarationSubject(CallableWithMetrics $callable): MetricSubject
    {
        return MetricSubject::declaration($callable->declarationPath);
    }
}
