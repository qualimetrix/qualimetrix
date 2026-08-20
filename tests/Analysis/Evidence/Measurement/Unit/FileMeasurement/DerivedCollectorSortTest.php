<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\FileMeasurement;

use LogicException;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Regression coverage for the derived-collector execution contract.
 *
 * CompositeCollector sorts derived collectors by their named `requires()`
 * dependencies and merges every output into the next collector's input.
 */
#[CoversClass(CompositeCollector::class)]
#[Group('regression')]
final class DerivedCollectorSortTest extends TestCase
{
    #[Test]
    public function itAccumulatesOutputsForDependentDerivedCollectors(): void
    {
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('raw:App\Service::method', 10),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'collector-b',
            requires: [],
            provides: ['intermediate'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('intermediate', ($bag->get('raw') ?? 0) * 2),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'collector-a',
            requires: ['collector-b'],
            provides: ['final'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('final', ($bag->get('intermediate') ?? 0) + 1),
        );

        // B runs before A, and A receives B's accumulated output.
        $composite = new CompositeCollector([$baseCollector], new DeclarationRegistrarFactory(), [$collectorB, $collectorA]);
        $result = $composite->collect(new SplFileInfo(__FILE__), [], RelativePath::fromString('DerivedCollectorSortTest.php'));

        self::assertSame(10, $result->metrics->get('raw:App\Service::method'));
        self::assertSame(20, $result->metrics->get($this->key('intermediate')));

        self::assertSame(21, $result->metrics->get($this->key('final')));
    }

    #[Test]
    public function itSortsDependentDerivedCollectorsBeforeCalculation(): void
    {
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('raw:App\Service::method', 10),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'collector-b',
            requires: [],
            provides: ['intermediate'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('intermediate', ($bag->get('raw') ?? 0) * 2),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'collector-a',
            requires: ['collector-b'],
            provides: ['final'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('final', ($bag->get('intermediate') ?? 0) + 1),
        );

        // Registration order is intentionally reverse dependency order.
        $composite = new CompositeCollector([$baseCollector], new DeclarationRegistrarFactory(), [$collectorA, $collectorB]);
        $result = $composite->collect(new SplFileInfo(__FILE__), [], RelativePath::fromString('DerivedCollectorSortTest.php'));

        self::assertSame(10, $result->metrics->get('raw:App\Service::method'));
        self::assertSame(20, $result->metrics->get($this->key('intermediate')));
        self::assertSame(21, $result->metrics->get($this->key('final')));
    }

    #[Test]
    public function itSortsAThreeCollectorChainRegisteredInReverseOrder(): void
    {
        // Chain: C -> B -> A (C depends on B, B depends on A)
        // Registered in reverse: C, B, A
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('input:test', 5),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'step-a',
            requires: [],
            provides: ['step_a_result'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('step_a_result', ($bag->get('input') ?? 0) * 10),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'step-b',
            requires: ['step-a'],
            provides: ['step_b_result'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('step_b_result', ($bag->get('step_a_result') ?? 0) + 100),
        );

        $collectorC = $this->createDerivedCollector(
            name: 'step-c',
            requires: ['step-b'],
            provides: ['step_c_result'],
            calculate: static fn(MetricBag $bag): MetricBag =>
                (new MetricBag())->with('step_c_result', ($bag->get('step_b_result') ?? 0) * 2),
        );

        // Reverse order: C, B, A. The dependency graph determines execution.
        $composite = new CompositeCollector([$baseCollector], new DeclarationRegistrarFactory(), [$collectorC, $collectorB, $collectorA]);
        $result = $composite->collect(new SplFileInfo(__FILE__), [], RelativePath::fromString('DerivedCollectorSortTest.php'));

        self::assertSame(50, $result->metrics->get($this->key('step_a_result')));
        self::assertSame(150, $result->metrics->get($this->key('step_b_result')));
        self::assertSame(300, $result->metrics->get($this->key('step_c_result')));
    }

    #[Test]
    public function itRejectsCyclicDerivedCollectorDependencies(): void
    {
        $baseCollector = $this->createBaseCollector(
            (new MetricBag())->with('value:fqn', 1),
        );

        $collectorA = $this->createDerivedCollector(
            name: 'collector-a',
            requires: ['collector-b'],
            provides: ['a'],
            calculate: static fn(MetricBag $bag): MetricBag => new MetricBag(),
        );

        $collectorB = $this->createDerivedCollector(
            name: 'collector-b',
            requires: ['collector-a'],
            provides: ['b'],
            calculate: static fn(MetricBag $bag): MetricBag => new MetricBag(),
        );

        $composite = new CompositeCollector([$baseCollector], new DeclarationRegistrarFactory(), [$collectorA, $collectorB]);

        self::expectException(LogicException::class);
        self::expectExceptionMessageMatches('/Cyclic dependency.*collector-a.*collector-b|Cyclic dependency.*collector-b.*collector-a/');

        $composite->collect(new SplFileInfo(__FILE__), [], RelativePath::fromString('DerivedCollectorSortTest.php'));
    }

    /**
     * @param list<string> $requires
     * @param list<string> $provides
     * @param callable(MetricBag): MetricBag $calculate
     */
    private function createDerivedCollector(
        string $name,
        array $requires,
        array $provides,
        callable $calculate,
    ): DerivedCollectorInterface {
        $mock = self::createStub(DerivedCollectorInterface::class);
        $mock->method('getName')->willReturn($name);
        $mock->method('requires')->willReturn($requires);
        $mock->method('provides')->willReturn($provides);
        $mock->method('getMetricDefinitions')->willReturn(array_map(
            static fn(string $metric): MetricDefinition => new MetricDefinition($metric, SymbolLevel::Callable),
            $provides,
        ));
        $mock->method('calculate')->willReturnCallback($calculate);

        return $mock;
    }

    private function createBaseCollector(MetricBag $metrics): MetricCollectorInterface&CallableMetricsProviderInterface
    {
        $callable = $this->callable($metrics);
        return new class ($metrics, $callable) implements MetricCollectorInterface, CallableMetricsProviderInterface {
            public function __construct(private MetricBag $metrics, private CallableWithMetrics $callable) {}
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
                return [];
            }
            public function getVisitor(): NodeVisitorAbstract
            {
                return new class extends NodeVisitorAbstract {};
            }
            public function collect(SplFileInfo $file, array $ast): MetricBag
            {
                return $this->metrics;
            }
            public function reset(): void {}
            public function getCallablesWithMetrics(RelativePath $file): array
            {
                return [$this->callable];
            }
        };
    }

    private function callable(MetricBag $metrics): CallableWithMetrics
    {
        $callableMetrics = new MetricBag();
        foreach ($metrics->all() as $name => $value) {
            $callableMetrics = $callableMetrics->with(explode(':', $name, 2)[0], $value);
        } return new CallableWithMetrics(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'method'), RelativePath::fromString('DerivedCollectorSortTest.php'), DeclarationOrdinal::fromRank(0)), 100, CallableKind::Method, null, null, new LogicalClassPath(SymbolPath::forClass('App', 'Service')), $callableMetrics);
    }
    private function key(string $metric): string
    {
        return $metric . ':method:' . $this->callable(new MetricBag())->declarationPath->toCanonical();
    }
}
