<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Metric;

use LogicException;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Metric\DerivedCollectorRunner;
use Qualimetrix\Core\Metric\CallableMetricsProviderInterface;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\ClassMetricsProviderInterface;
use Qualimetrix\Core\Metric\ClassWithMetrics;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

#[CoversClass(DerivedCollectorRunner::class)]
final class DerivedCollectorRunnerTest extends TestCase
{
    #[Test]
    public function itRejectsUnknownRequiredCollector(): void
    {
        $collector = self::createStub(DerivedCollectorInterface::class);
        $collector->method('getName')->willReturn('derived');
        $collector->method('requires')->willReturn(['missing-base']);
        $collector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('derived', SymbolLevel::Callable),
        ]);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('missing-base');

        (new DerivedCollectorRunner([$collector]))->apply(
            new MetricBag(),
            [],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );
    }

    #[Test]
    public function itRejectsCyclicDependenciesBeforeReadingSubjects(): void
    {
        $first = $this->derived('first', ['second'], ['first'], static fn(MetricBag $bag): MetricBag => $bag);
        $second = $this->derived('second', ['first'], ['second'], static fn(MetricBag $bag): MetricBag => $bag);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Cyclic dependency');

        (new DerivedCollectorRunner([$first, $second]))->apply(
            new MetricBag(),
            [],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );
    }

    #[Test]
    public function itOrdersIndependentCollectorsByName(): void
    {
        $calls = [];
        $zeta = $this->derived('zeta', [], ['zeta'], static function (MetricBag $bag) use (&$calls): MetricBag {
            $calls[] = 'zeta';

            return (new MetricBag())->with('zeta', 1);
        });
        $alpha = $this->derived('alpha', [], ['alpha'], static function (MetricBag $bag) use (&$calls): MetricBag {
            $calls[] = 'alpha';

            return (new MetricBag())->with('alpha', 1);
        });
        $callable = $this->callable(10, 3);

        (new DerivedCollectorRunner([$zeta, $alpha]))->apply(
            new MetricBag(),
            [$this->baseCollector([$callable])],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );

        self::assertSame(['alpha', 'zeta'], $calls);
    }

    #[Test]
    public function itAccumulatesMultiLevelDerivedMetricsAtTheirDeclaredSubjects(): void
    {
        $callable = $this->callable(10, 3);
        $class = $this->class(20, 7);
        $first = $this->derived(
            'first',
            ['base'],
            ['callable.step', 'class.step'],
            static fn(MetricBag $bag): MetricBag => (new MetricBag())->with('step', ($bag->get('raw') ?? 0) + 1),
            [
                new MetricDefinition('step', SymbolLevel::Callable),
                new MetricDefinition('step', SymbolLevel::Class_),
            ],
        );
        $second = $this->derived(
            'second',
            ['first'],
            ['final', 'class.final'],
            static fn(MetricBag $bag): MetricBag => (new MetricBag())
                ->with('final', ($bag->get('step') ?? 0) * 2)
                ->with('class.final', ($bag->get('step') ?? 0) * 3),
            [
                new MetricDefinition('final', SymbolLevel::Callable),
                new MetricDefinition('class.final', SymbolLevel::Class_),
            ],
        );

        $result = (new DerivedCollectorRunner([$second, $first]))->apply(
            new MetricBag(),
            [$this->baseCollector([$callable], [$class])],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );

        self::assertSame(4, $result->get($this->callableKey('step', $callable)));
        self::assertSame(8, $result->get($this->callableKey('final', $callable)));
        self::assertSame(24, $result->get($this->classKey('class.final', $class)));
        self::assertNull($result->get($this->callableKey('class.final', $callable)));
        self::assertNull($result->get($this->classKey('final', $class)));
    }

    #[Test]
    public function itKeepsDuplicateLogicalCallablesAtTheirExactDeclarations(): void
    {
        $first = $this->callable(10, 3);
        $second = $this->callable(20, 5);
        $derived = $this->derived(
            'derived',
            ['base'],
            ['score'],
            static fn(MetricBag $bag): MetricBag => (new MetricBag())->with('score', ($bag->get('raw') ?? 0) * 10),
        );

        $result = (new DerivedCollectorRunner([$derived]))->apply(
            new MetricBag(),
            [$this->baseCollector([$first, $second])],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );

        self::assertSame(30, $result->get($this->callableKey('score', $first)));
        self::assertSame(50, $result->get($this->callableKey('score', $second)));
        self::assertNotSame($this->callableKey('score', $first), $this->callableKey('score', $second));
    }

    #[Test]
    public function itDoesNotCalculateWhenThereAreNoTypedSubjects(): void
    {
        $collector = $this->derived(
            'derived',
            ['base'],
            ['score'],
            static function (MetricBag $bag): MetricBag {
                self::fail('Derived calculation must not run without a typed subject');
            },
        );
        $base = $this->baseCollector([], [], MetricBag::fromArray(['file.raw' => 9]));

        $result = (new DerivedCollectorRunner([$collector]))->apply(
            MetricBag::fromArray(['file.raw' => 9]),
            [$base],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );

        self::assertSame(['file.raw' => 9], $result->all());
    }

    #[Test]
    public function itRejectsDerivedDependenciesMissingTheCurrentLevel(): void
    {
        $callableOnly = $this->derived(
            'callable-only',
            ['base'],
            ['callable.step'],
            static fn(MetricBag $bag): MetricBag => (new MetricBag())->with('step', 1),
        );
        $classDownstream = $this->derived(
            'class-downstream',
            ['callable-only'],
            ['class.result'],
            static fn(MetricBag $bag): MetricBag => (new MetricBag())->with('result', 1),
            [new MetricDefinition('result', SymbolLevel::Class_)],
        );

        self::expectException(LogicException::class);
        self::expectExceptionMessage('class-downstream');
        self::expectExceptionMessage('class');

        (new DerivedCollectorRunner([$callableOnly, $classDownstream]))->apply(
            new MetricBag(),
            [$this->baseCollector([], [$this->class(20, 7)])],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );
    }

    #[Test]
    public function itDoesNotLeakDerivedMetricsBetweenCallableAndClassLevels(): void
    {
        $callable = $this->callable(10, 3);
        $class = $this->class(20, 7);
        $upstream = $this->derived(
            'upstream',
            ['base'],
            ['callable.input', 'class.input'],
            static fn(MetricBag $bag): MetricBag => (new MetricBag())
                ->with('callable.input', 10)
                ->with('class.input', 20),
            [
                new MetricDefinition('callable.input', SymbolLevel::Callable),
                new MetricDefinition('class.input', SymbolLevel::Class_),
            ],
        );
        $downstream = $this->derived(
            'downstream',
            ['upstream'],
            ['callable.result', 'class.result'],
            static fn(MetricBag $bag): MetricBag => (new MetricBag())
                ->with('callable.result', $bag->has('class.input') ? 99 : 1)
                ->with('class.result', $bag->has('callable.input') ? 99 : 1),
            [
                new MetricDefinition('callable.result', SymbolLevel::Callable),
                new MetricDefinition('class.result', SymbolLevel::Class_),
            ],
        );

        $result = (new DerivedCollectorRunner([$upstream, $downstream]))->apply(
            new MetricBag(),
            [$this->baseCollector([$callable], [$class])],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );

        self::assertSame(1, $result->get($this->callableKey('callable.result', $callable)));
        self::assertSame(1, $result->get($this->classKey('class.result', $class)));
    }

    #[Test]
    public function itRejectsDuplicateBaseCollectorNames(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('Duplicate base collector name: base');

        (new DerivedCollectorRunner([]))->apply(
            new MetricBag(),
            [$this->baseCollector(), $this->baseCollector()],
            RelativePath::fromString('DerivedCollectorRunnerTest.php'),
        );
    }

    /**
     * @param list<string> $requires
     * @param list<string> $provides
     * @param callable(MetricBag): MetricBag $calculate
     * @param list<MetricDefinition>|null $definitions
     */
    private function derived(string $name, array $requires, array $provides, callable $calculate, ?array $definitions = null): DerivedCollectorInterface
    {
        $collector = self::createStub(DerivedCollectorInterface::class);
        $collector->method('getName')->willReturn($name);
        $collector->method('requires')->willReturn($requires);
        $collector->method('provides')->willReturn($provides);
        $collector->method('getMetricDefinitions')->willReturn($definitions ?? array_map(
            static fn(string $metric): MetricDefinition => new MetricDefinition($metric, SymbolLevel::Callable),
            $provides,
        ));
        $collector->method('calculate')->willReturnCallback($calculate);

        return $collector;
    }

    /**
     * @param list<CallableWithMetrics> $callables
     * @param list<ClassWithMetrics> $classes
     */
    private function baseCollector(array $callables = [], array $classes = [], ?MetricBag $bag = null): MetricCollectorInterface&CallableMetricsProviderInterface&ClassMetricsProviderInterface
    {
        return new class ($callables, $classes, $bag ?? new MetricBag()) implements MetricCollectorInterface, CallableMetricsProviderInterface, ClassMetricsProviderInterface {
            /**
             * @param list<CallableWithMetrics> $callables
             * @param list<ClassWithMetrics> $classes
             */
            public function __construct(
                private readonly array $callables,
                private readonly array $classes,
                private readonly MetricBag $bag,
            ) {}

            public function getName(): string
            {
                return 'base';
            }

            public function provides(): array
            {
                return [];
            }

            public function getMetricDefinitions(): array
            {
                return [
                    new MetricDefinition('raw', SymbolLevel::Callable),
                    new MetricDefinition('raw', SymbolLevel::Class_),
                ];
            }

            public function getVisitor(): NodeVisitorAbstract
            {
                return new class extends NodeVisitorAbstract {};
            }

            public function collect(SplFileInfo $file, array $ast): MetricBag
            {
                return $this->bag;
            }

            public function reset(): void {}

            public function getCallablesWithMetrics(RelativePath $file): array
            {
                return array_values($this->callables);
            }

            public function getClassesWithMetrics(RelativePath $file): array
            {
                return array_values($this->classes);
            }
        };
    }

    private function callable(int $startFilePos, int $raw): CallableWithMetrics
    {
        $file = RelativePath::fromString('DerivedCollectorRunnerTest.php');

        return new CallableWithMetrics(
            new DeclarationPath(SymbolPath::forMethod('App', 'Service', 'run'), $file, $startFilePos),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
            MetricBag::fromArray(['raw' => $raw]),
        );
    }

    private function class(int $startFilePos, int $raw): ClassWithMetrics
    {
        return new ClassWithMetrics(
            new DeclarationPath(
                SymbolPath::forClass('App', 'Service'),
                RelativePath::fromString('DerivedCollectorRunnerTest.php'),
                $startFilePos,
            ),
            1,
            MetricBag::fromArray(['raw' => $raw]),
        );
    }

    private function callableKey(string $metric, CallableWithMetrics $callable): string
    {
        return $metric . ':' . $callable->kind->value . ':' . MetricSubject::declaration($callable->declarationPath)->toCanonical();
    }

    private function classKey(string $metric, ClassWithMetrics $class): string
    {
        return $metric . ':' . $class->subject->toCanonical();
    }
}
