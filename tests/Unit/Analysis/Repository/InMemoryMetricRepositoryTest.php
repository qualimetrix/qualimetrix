<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(InMemoryMetricRepository::class)]
final class InMemoryMetricRepositoryTest extends TestCase
{
    #[Test]
    public function itStoresAndRetrievesMetrics(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forMethod('App\\Service', 'UserService', 'calculate');
        $metrics = (new MetricBag())->with('ccn', 5);

        $repository->add($symbol, $metrics, 'src/Service/UserService.php', 42);

        $retrieved = $repository->get($symbol);

        self::assertInstanceOf(MetricBag::class, $retrieved); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(5, $retrieved->get('ccn'));
    }

    #[Test]
    public function itReturnsEmptyMetricBagForUnknownSymbol(): void
    {
        $repository = new InMemoryMetricRepository();

        $retrieved = $repository->get(SymbolPath::forClass('Unknown', 'Class'));

        self::assertInstanceOf(MetricBag::class, $retrieved); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame([], $retrieved->all());
    }

    #[Test]
    public function itMergesMetricsForSameSymbol(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forNamespace('App\\Service');

        // First add
        $metrics1 = (new MetricBag())
            ->with('classCount.sum', 10)
            ->with('methodCount', 50);
        $repository->add($symbol, $metrics1, 'src/Service/UserService.php', 0);

        // Second add should merge
        $metrics2 = (new MetricBag())
            ->with('ccn.sum', 100)
            ->with('ccn.avg', 3.5);
        $repository->add($symbol, $metrics2, 'src/Service/UserService.php', 0);

        $retrieved = $repository->get($symbol);

        self::assertInstanceOf(MetricBag::class, $retrieved); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(10, $retrieved->get('classCount.sum'));
        self::assertSame(50, $retrieved->get('methodCount'));
        self::assertSame(100, $retrieved->get('ccn.sum'));
        self::assertSame(3.5, $retrieved->get('ccn.avg'));
    }

    #[Test]
    public function itChecksExistence(): void
    {
        $repository = new InMemoryMetricRepository();

        $existing = SymbolPath::forClass('App', 'Test');
        $repository->add($existing, new MetricBag(), 'test.php', 1);

        self::assertTrue($repository->has($existing));
        self::assertFalse($repository->has(SymbolPath::forClass('Unknown', 'Class')));
    }

    #[Test]
    public function itIteratesOverMethods(): void
    {
        $repository = new InMemoryMetricRepository();

        $method1 = SymbolPath::forMethod('App', 'Service', 'method1');
        $method2 = SymbolPath::forMethod('App', 'Service', 'method2');
        $class = SymbolPath::forClass('App', 'Service');

        $repository->add($method1, new MetricBag(), 'test.php', 10);
        $repository->add($method2, new MetricBag(), 'test.php', 20);
        $repository->add($class, new MetricBag(), 'test.php', 1);

        $methods = iterator_to_array($repository->all(SymbolType::Method), false);

        self::assertCount(2, $methods);
    }

    #[Test]
    public function itIteratesOverClasses(): void
    {
        $repository = new InMemoryMetricRepository();

        $method = SymbolPath::forMethod('App', 'Service', 'method');
        $class1 = SymbolPath::forClass('App', 'Service');
        $class2 = SymbolPath::forClass('App', 'Repository');

        $repository->add($method, new MetricBag(), 'test.php', 10);
        $repository->add($class1, new MetricBag(), 'test.php', 1);
        $repository->add($class2, new MetricBag(), 'test2.php', 1);

        $classes = iterator_to_array($repository->all(SymbolType::Class_), false);

        self::assertCount(2, $classes);
    }

    #[Test]
    public function itIteratesOverNamespaces(): void
    {
        $repository = new InMemoryMetricRepository();

        $ns1 = SymbolPath::forNamespace('App\\Service');
        $ns2 = SymbolPath::forNamespace('App\\Repository');
        $class = SymbolPath::forClass('App\\Service', 'Test');

        $ns1Metrics = (new MetricBag())->with('classCount.sum', 5);
        $repository->add($ns1, $ns1Metrics, 'test.php', 0);

        $ns2Metrics = (new MetricBag())->with('classCount.sum', 3);
        $repository->add($ns2, $ns2Metrics, 'test2.php', 0);

        $repository->add($class, new MetricBag(), 'test.php', 1);

        $namespaces = iterator_to_array($repository->all(SymbolType::Namespace_), false);

        self::assertCount(2, $namespaces);
    }

    #[Test]
    public function itReturnsAllNamespaces(): void
    {
        $repository = new InMemoryMetricRepository();

        $repository->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            new MetricBag(),
            'src/Service/UserService.php',
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'UserRepository'),
            new MetricBag(),
            'src/Repository/UserRepository.php',
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            new MetricBag(),
            'src/Service/OrderService.php',
            1,
        );

        $namespaces = $repository->getNamespaces();

        self::assertSame(['App\\Repository', 'App\\Service'], $namespaces);
    }

    #[Test]
    public function itReturnsSymbolsForNamespace(): void
    {
        $repository = new InMemoryMetricRepository();

        $repository->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            new MetricBag(),
            'src/Service/UserService.php',
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'UserRepository'),
            new MetricBag(),
            'src/Repository/UserRepository.php',
            1,
        );
        $repository->add(
            SymbolPath::forMethod('App\\Service', 'UserService', 'find'),
            new MetricBag(),
            'src/Service/UserService.php',
            10,
        );

        $serviceSymbols = iterator_to_array($repository->forNamespace('App\\Service'), false);

        self::assertCount(2, $serviceSymbols);
    }

    #[Test]
    public function itMergesWithAnotherRepository(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $repo2 = new InMemoryMetricRepository();

        // Add to first repository
        $metrics1 = (new MetricBag())->with('ccn', 5);
        $repo1->add(
            SymbolPath::forMethod('App', 'ServiceA', 'method1'),
            $metrics1,
            'ServiceA.php',
            10,
        );

        // Add to second repository
        $metrics2 = (new MetricBag())->with('ccn', 10);
        $repo2->add(
            SymbolPath::forMethod('App', 'ServiceB', 'method2'),
            $metrics2,
            'ServiceB.php',
            20,
        );

        $merged = $repo1->mergeWith($repo2);

        // Both symbols should exist in merged repository
        self::assertTrue($merged->has(SymbolPath::forMethod('App', 'ServiceA', 'method1')));
        self::assertTrue($merged->has(SymbolPath::forMethod('App', 'ServiceB', 'method2')));

        // Metrics should be correct
        self::assertSame(5, $merged->get(SymbolPath::forMethod('App', 'ServiceA', 'method1'))->get('ccn'));
        self::assertSame(10, $merged->get(SymbolPath::forMethod('App', 'ServiceB', 'method2'))->get('ccn'));

        // Original repositories should be unchanged
        self::assertFalse($repo1->has(SymbolPath::forMethod('App', 'ServiceB', 'method2')));
        self::assertFalse($repo2->has(SymbolPath::forMethod('App', 'ServiceA', 'method1')));
    }

    #[Test]
    public function itMergesOverlappingSymbols(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $repo2 = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App', 'Service');

        // Add metrics to first repository
        $metrics1 = (new MetricBag())
            ->with('methodCount', 5)
            ->with('loc', 100);
        $repo1->add($symbol, $metrics1, 'Service.php', 1);

        // Add different metrics to second repository for same symbol
        $metrics2 = (new MetricBag())
            ->with('ccn.sum', 25)
            ->with('loc', 150); // Override
        $repo2->add($symbol, $metrics2, 'Service.php', 1);

        $merged = $repo1->mergeWith($repo2);

        $result = $merged->get($symbol);

        // Metrics from both should be present, with second overriding duplicates
        self::assertSame(5, $result->get('methodCount')); // From repo1
        self::assertSame(25, $result->get('ccn.sum')); // From repo2
        self::assertSame(150, $result->get('loc')); // Overridden by repo2
    }

    #[Test]
    public function itMergesWithEmptyRepository(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $repo2 = new InMemoryMetricRepository();

        $metrics = (new MetricBag())->with('ccn', 5);
        $repo1->add(
            SymbolPath::forMethod('App', 'Service', 'method'),
            $metrics,
            'Service.php',
            10,
        );

        // Merge with empty
        $merged = $repo1->mergeWith($repo2);

        self::assertTrue($merged->has(SymbolPath::forMethod('App', 'Service', 'method')));
        self::assertSame(5, $merged->get(SymbolPath::forMethod('App', 'Service', 'method'))->get('ccn'));
    }

    #[Test]
    public function itUpdatesLineFromZeroToPositiveOnSubsequentAdd(): void
    {
        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forClass('App\\Service', 'UserService');

        // First add with line=0 (e.g., from aggregator)
        $repository->add($symbol, (new MetricBag())->with('wmc', 10), 'src/Service/UserService.php', 0);

        // Second add with real line number
        $repository->add($symbol, (new MetricBag())->with('loc', 100), 'src/Service/UserService.php', 42);

        $infos = iterator_to_array($repository->all(SymbolType::Class_), false);
        $info = $infos[0];

        self::assertSame(42, $info->line);
    }

    #[Test]
    public function itKeepsPositiveLineWhenSubsequentAddHasZero(): void
    {
        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forClass('App\\Service', 'UserService');

        // First add with real line number
        $repository->add($symbol, (new MetricBag())->with('loc', 100), 'src/Service/UserService.php', 42);

        // Second add with line=0 should NOT overwrite
        $repository->add($symbol, (new MetricBag())->with('wmc', 10), 'src/Service/UserService.php', 0);

        $infos = iterator_to_array($repository->all(SymbolType::Class_), false);
        $info = $infos[0];

        self::assertSame(42, $info->line);
    }

    #[Test]
    public function mergeWithUpdatesLineFromZeroToPositive(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $repo2 = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App', 'Service');

        // repo1 has line=0
        $repo1->add($symbol, (new MetricBag())->with('wmc', 10), 'Service.php', 0);

        // repo2 has line=42
        $repo2->add($symbol, (new MetricBag())->with('loc', 100), 'Service.php', 42);

        $merged = $repo1->mergeWith($repo2);

        $infos = iterator_to_array($merged->all(SymbolType::Class_), false);
        $info = $infos[0];

        self::assertSame(42, $info->line);
    }

    #[Test]
    public function testAddScalarDoesNotDuplicateDataBagEntries(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $metrics = (new MetricBag())
            ->with('ccn', 5)
            ->withEntry('dependencies', ['name' => 'Foo'])
            ->withEntry('dependencies', ['name' => 'Bar']);

        $repository->add($symbol, $metrics, 'src/Service/UserService.php', 1);

        $repository->addScalar($symbol, 'loc', 100);

        $retrieved = $repository->get($symbol);

        self::assertSame(2, $retrieved->entryCount('dependencies'));
    }

    #[Test]
    public function testAddScalarIgnoresNonExistentSymbol(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App\\Service', 'NonExistent');

        $repository->addScalar($symbol, 'ccn', 10);

        self::assertFalse($repository->has($symbol));
    }

    #[Test]
    public function testAddScalarUpdatesExistingMetric(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $metrics = (new MetricBag())
            ->with('foo', 10)
            ->with('bar', 42);

        $repository->add($symbol, $metrics, 'src/Service/UserService.php', 1);

        $repository->addScalar($symbol, 'foo', 20);

        $retrieved = $repository->get($symbol);

        self::assertSame(20, $retrieved->get('foo'));
        self::assertSame(42, $retrieved->get('bar'));
    }
}
