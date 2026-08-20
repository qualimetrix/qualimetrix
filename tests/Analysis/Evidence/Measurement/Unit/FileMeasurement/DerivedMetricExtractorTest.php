<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\FileMeasurement;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\CallableToClassAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\ClassToNamespaceAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\DerivedMetricExtractor;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
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
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]);
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
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);
        $extractor = new DerivedMetricExtractor(new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]));

        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $file = RelativePath::fromString('src/Service.php');
        $callable = new CallableWithMetrics(
            DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0)),
            701,
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
        self::assertNotSame($callable->startFilePos, $declarations[0]->line);
        self::assertSame(80.0, $repository->getSubject($subject)->get('mi'));
    }

    #[Test]
    public function itExtractsDerivedMetricsOnlyForTheirDeclaredTypedTargets(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi', 'typeCoverage.pct']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
            new MetricDefinition('typeCoverage.pct', SymbolLevel::Class_),
        ]);
        $extractor = new DerivedMetricExtractor(new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]));

        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Service.php');
        $callable = new CallableWithMetrics(
            DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'run'), $file, DeclarationOrdinal::fromRank(0)),
            701,
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
            MetricBag::fromArray(['ccn' => 3]),
        );
        $class = new ClassWithMetrics(
            DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $file, DeclarationOrdinal::fromRank(0)),
            500,
            12,
            MetricBag::fromArray(['typeCoverage.paramTotal' => 2]),
        );
        $repository->addCallable($callable);
        $repository->addSubject($class->subject, $class->metrics, $file, $class->line);

        $extractor->extract(
            $repository,
            MetricBag::fromArray([
                $this->derivedKey('mi', $callable) => 83.5,
                $this->derivedClassKey('typeCoverage.pct', $class) => 100.0,
            ]),
            [$callable],
            $file,
            [$class],
        );

        self::assertSame(83.5, $repository->getSubject($this->declarationSubject($callable))->get('mi'));
        self::assertNull($repository->getSubject($this->declarationSubject($callable))->get('typeCoverage.pct'));
        self::assertSame(100.0, $repository->getSubject($class->subject)->get('typeCoverage.pct'));
        self::assertNull($repository->getSubject($class->subject)->get('mi'));
    }

    #[Test]
    public function itIgnoresMalformedWrongKindAndWrongSubjectCanonicalKeys(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $callable = $this->callable($symbol, MetricBag::fromArray(['ccn' => 3]));
        $repository->addCallable($callable);
        $subject = MetricSubject::declaration($callable->declarationPath);
        $otherSubject = MetricSubject::declaration(DeclarationPath::of($symbol, $callable->declarationPath->file, DeclarationOrdinal::fromRank(0)));

        $fileBag = MetricBag::fromArray([
            'mi:' . $subject->toCanonical() => 85.5,
            'mi:function:' . $subject->toCanonical() => 90.0,
            'mi:method:' . $otherSubject->toCanonical() => 75.0,
        ]);

        (new DerivedMetricExtractor(new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector])))->extract(
            $repository,
            $fileBag,
            [$callable],
            RelativePath::fromString('tmp/test.php'),
        );

        self::assertNull($repository->getSubject($subject)->get('mi'));
    }

    #[Test]
    public function itIgnoresNonDerivedMetrics(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]);
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
        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory());
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
    public function itDoesNotCreateAnUnregisteredExactDeclarationSubject(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('tmp/test.php');
        $class = new ClassWithMetrics(
            DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $file, DeclarationOrdinal::fromRank(1)),
            4,
            1,
            MetricBag::fromArray(['tcc' => 0.5]),
        );
        $repository->addSubject($class->subject, $class->metrics, $file, $class->line);
        $callable = $this->callable(SymbolPath::forMethod('App', 'Service', 'run'), new MetricBag());

        $fileBag = MetricBag::fromArray([$this->derivedKey('mi', $callable) => 85.5]);

        (new DerivedMetricExtractor(new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector])))->extract(
            $repository,
            $fileBag,
            [$callable],
            $file,
        );

        self::assertFalse($repository->hasSubject(MetricSubject::declaration($callable->declarationPath)));
        self::assertNull($repository->getSubject($class->subject)->get('mi'));
    }

    #[Test]
    public function itPreservesGlobalNamespaceMethodMetricsByExactDeclarationIdentity(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]);
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
    public function itIgnoresBareMetricNamesForAnExistingExactSubject(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $repository = new InMemoryMetricRepository();
        $callable = $this->callable(SymbolPath::forMethod('App', 'Service', 'run'), MetricBag::fromArray(['ccn' => 3]));
        $repository->addCallable($callable);

        $fileBag = MetricBag::fromArray(['mi' => 85.5]);

        (new DerivedMetricExtractor(new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector])))->extract(
            $repository,
            $fileBag,
            [$callable],
            RelativePath::fromString('tmp/test.php'),
        );

        self::assertSame(3, $repository->getSubject(MetricSubject::declaration($callable->declarationPath))->get('ccn'));
        self::assertNull($repository->getSubject(MetricSubject::declaration($callable->declarationPath))->get('mi'));
    }

    #[Test]
    public function itResolvesDerivedMetricsForStandaloneFunctions(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]);
        $extractor = new DerivedMetricExtractor($compositeCollector);

        $repository = new InMemoryMetricRepository();
        // Register a function (not a class) in the repository
        $functionSymbol = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $callable = $this->callable($functionSymbol, MetricBag::fromArray(['ccn' => 5]));
        $repository->addCallable($callable);

        // Derived collector outputs MI keyed by the exact callable declaration.
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
    public function itPreservesGlobalFunctionMetricsByExactDeclarationIdentity(): void
    {
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]);
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
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);
        $extractor = new DerivedMetricExtractor(new CompositeCollector([], new DeclarationRegistrarFactory(), [$derivedCollector]));

        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $first = new CallableWithMetrics(
            DeclarationPath::of($symbol, RelativePath::fromString('src/First.php'), DeclarationOrdinal::fromRank(0)),
            410,
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['ccn' => 3]),
            17,
        );
        $second = new CallableWithMetrics(
            DeclarationPath::of($symbol, RelativePath::fromString('src/Second.php'), DeclarationOrdinal::fromRank(0)),
            920,
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
        $profiler = self::createStub(ProfilerInterface::class);
        (new CallableToClassAggregator($profiler))->aggregate($repository, [$definition]);
        (new ClassToNamespaceAggregator($profiler))->aggregate($repository, [$definition]);

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
                DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(1)),
                0,
                CallableKind::Function,
                null,
                null,
                null,
                $metrics,
            );
        }

        return new CallableWithMetrics(
            DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(1)),
            0,
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

    private function derivedClassKey(string $metric, ClassWithMetrics $class): string
    {
        return $metric . ':' . $class->subject->toCanonical();
    }

    private function declarationSubject(CallableWithMetrics $callable): MetricSubject
    {
        return MetricSubject::declaration($callable->declarationPath);
    }
}
