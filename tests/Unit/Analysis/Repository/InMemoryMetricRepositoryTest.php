<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Repository;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
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

        $this->addCallable($repository, $symbol, $metrics, RelativePath::fromString('src/Service/UserService.php'), 420);

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
        $repository->add($symbol, $metrics1, RelativePath::fromString('src/Service/UserService.php'), 0);

        // Second add should merge
        $metrics2 = (new MetricBag())
            ->with('ccn.sum', 100)
            ->with('ccn.avg', 3.5);
        $repository->add($symbol, $metrics2, RelativePath::fromString('src/Service/UserService.php'), 0);

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
        $repository->add($existing, new MetricBag(), RelativePath::fromString('test.php'), 1);

        self::assertTrue($repository->has($existing));
        self::assertFalse($repository->has(SymbolPath::forClass('Unknown', 'Class')));
    }

    #[Test]
    public function itPreservesExistingFileWhenMergingWithNullFile(): void
    {
        // Pins the CouplingCollector contract: graph-derived metric collectors call
        // ->add(symbol, metrics, null, …) on existing class/namespace symbols.
        // The repository must NOT overwrite the original SymbolInfo's file in that case;
        // otherwise downstream consumers (formatters, ranking) lose file association.
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $originalFile = RelativePath::fromString('src/Service/UserService.php');

        $repository->add($symbol, (new MetricBag())->with('methodCount', 5), $originalFile, 10);
        // Graph-phase merge with no file context (CouplingCollector pattern).
        $repository->add($symbol, (new MetricBag())->with('cbo', 3), null, 0);

        $symbols = iterator_to_array($repository->all(SymbolType::Class_), false);

        self::assertCount(1, $symbols);
        self::assertNotNull($symbols[0]->file);
        self::assertTrue($symbols[0]->file->equals($originalFile));
        self::assertSame(10, $symbols[0]->line);

        $bag = $repository->get($symbol);
        self::assertSame(5, $bag->get('methodCount'));
        self::assertSame(3, $bag->get('cbo'));
    }

    #[Test]
    public function itIteratesOverMethods(): void
    {
        $repository = new InMemoryMetricRepository();

        $method1 = SymbolPath::forMethod('App', 'Service', 'method1');
        $method2 = SymbolPath::forMethod('App', 'Service', 'method2');
        $class = SymbolPath::forClass('App', 'Service');

        $this->addCallable($repository, $method1, new MetricBag(), RelativePath::fromString('test.php'), 100);
        $this->addCallable($repository, $method2, new MetricBag(), RelativePath::fromString('test.php'), 200);
        $repository->add($class, new MetricBag(), RelativePath::fromString('test.php'), 1);

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

        $this->addCallable($repository, $method, new MetricBag(), RelativePath::fromString('test.php'), 100);
        $repository->add($class1, new MetricBag(), RelativePath::fromString('test.php'), 1);
        $repository->add($class2, new MetricBag(), RelativePath::fromString('test2.php'), 1);

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
        $repository->add($ns1, $ns1Metrics, RelativePath::fromString('test.php'), 0);

        $ns2Metrics = (new MetricBag())->with('classCount.sum', 3);
        $repository->add($ns2, $ns2Metrics, RelativePath::fromString('test2.php'), 0);

        $repository->add($class, new MetricBag(), RelativePath::fromString('test.php'), 1);

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
            RelativePath::fromString('src/Service/UserService.php'),
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'UserRepository'),
            new MetricBag(),
            RelativePath::fromString('src/Repository/UserRepository.php'),
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            new MetricBag(),
            RelativePath::fromString('src/Service/OrderService.php'),
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
            RelativePath::fromString('src/Service/UserService.php'),
            1,
        );
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'UserRepository'),
            new MetricBag(),
            RelativePath::fromString('src/Repository/UserRepository.php'),
            1,
        );
        $this->addCallable(
            $repository,
            SymbolPath::forMethod('App\\Service', 'UserService', 'find'),
            new MetricBag(),
            RelativePath::fromString('src/Service/UserService.php'),
            100,
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
        $this->addCallable(
            $repo1,
            SymbolPath::forMethod('App', 'ServiceA', 'method1'),
            $metrics1,
            RelativePath::fromString('ServiceA.php'),
            100,
        );

        // Add to second repository
        $metrics2 = (new MetricBag())->with('ccn', 10);
        $this->addCallable(
            $repo2,
            SymbolPath::forMethod('App', 'ServiceB', 'method2'),
            $metrics2,
            RelativePath::fromString('ServiceB.php'),
            200,
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
        $repo1->add($symbol, $metrics1, RelativePath::fromString('Service.php'), 1);

        // Add different metrics to second repository for same symbol
        $metrics2 = (new MetricBag())
            ->with('ccn.sum', 25)
            ->with('loc', 150); // Override
        $repo2->add($symbol, $metrics2, RelativePath::fromString('Service.php'), 1);

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
        $this->addCallable(
            $repo1,
            SymbolPath::forMethod('App', 'Service', 'method'),
            $metrics,
            RelativePath::fromString('Service.php'),
            100,
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
        $repository->add($symbol, (new MetricBag())->with('wmc', 10), RelativePath::fromString('src/Service/UserService.php'), 0);

        // Second add with real line number
        $repository->add($symbol, (new MetricBag())->with('loc', 100), RelativePath::fromString('src/Service/UserService.php'), 42);

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
        $repository->add($symbol, (new MetricBag())->with('loc', 100), RelativePath::fromString('src/Service/UserService.php'), 42);

        // Second add with line=0 should NOT overwrite
        $repository->add($symbol, (new MetricBag())->with('wmc', 10), RelativePath::fromString('src/Service/UserService.php'), 0);

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
        $repo1->add($symbol, (new MetricBag())->with('wmc', 10), RelativePath::fromString('Service.php'), 0);

        // repo2 has line=42
        $repo2->add($symbol, (new MetricBag())->with('loc', 100), RelativePath::fromString('Service.php'), 42);

        $merged = $repo1->mergeWith($repo2);

        $infos = iterator_to_array($merged->all(SymbolType::Class_), false);
        $info = $infos[0];

        self::assertSame(42, $info->line);
    }

    #[Test]
    public function itAddScalarDoesNotDuplicateDataBagEntries(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $metrics = (new MetricBag())
            ->with('ccn', 5)
            ->withEntry('dependencies', ['name' => 'Foo'])
            ->withEntry('dependencies', ['name' => 'Bar']);

        $repository->add($symbol, $metrics, RelativePath::fromString('src/Service/UserService.php'), 1);

        $repository->addScalar($symbol, 'loc', 100);

        $retrieved = $repository->get($symbol);

        self::assertSame(2, $retrieved->entryCount('dependencies'));
    }

    #[Test]
    public function itAddScalarIgnoresNonExistentSymbol(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App\\Service', 'NonExistent');

        $repository->addScalar($symbol, 'ccn', 10);

        self::assertFalse($repository->has($symbol));
    }

    #[Test]
    public function itAddScalarUpdatesExistingMetric(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $metrics = (new MetricBag())
            ->with('foo', 10)
            ->with('bar', 42);

        $repository->add($symbol, $metrics, RelativePath::fromString('src/Service/UserService.php'), 1);

        $repository->addScalar($symbol, 'foo', 20);

        $retrieved = $repository->get($symbol);

        self::assertSame(20, $retrieved->get('foo'));
        self::assertSame(42, $retrieved->get('bar'));
    }

    #[Test]
    public function itRejectsDeclarationSymbolsFromAggregateAdd(): void
    {
        $repository = new InMemoryMetricRepository();

        $this->expectException(InvalidArgumentException::class);
        $repository->add(
            SymbolPath::forMethod('App', 'Service', 'method'),
            new MetricBag(),
            RelativePath::fromString('Service.php'),
            10,
        );
    }

    #[Test]
    public function itPromotesAnExactSubjectToCallableMetadataRegardlessOfInsertionOrder(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $file = RelativePath::fromString('src/Service.php');
        $declaration = new DeclarationPath($symbol, $file, 420);
        $subject = MetricSubject::declaration($declaration);
        $callable = new CallableWithMetrics(
            $declaration,
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
            MetricBag::fromArray(['ccn' => 3]),
            17,
        );

        $subjectFirst = new InMemoryMetricRepository();
        $subjectFirst->addSubject($subject, MetricBag::fromArray(['loc' => 8]), $file, 17);
        $subjectFirst->addCallable($callable);

        $callableFirst = new InMemoryMetricRepository();
        $callableFirst->addCallable($callable);
        $callableFirst->addSubject($subject, MetricBag::fromArray(['loc' => 8]), $file, 17);

        foreach ([$subjectFirst, $callableFirst] as $repository) {
            $callables = iterator_to_array($repository->allCallables(), false);
            self::assertCount(1, $callables);
            self::assertSame(CallableKind::Method, $callables[0]->callableKind);
            self::assertSame(17, $callables[0]->line);
            self::assertCount(1, iterator_to_array($repository->allDeclarations(), false));
            self::assertCount(1, iterator_to_array($repository->allLogicalClasses(), false));
            self::assertCount(2, $repository->forNamespace('App'));
            self::assertSame(8, $repository->getSubject($subject)->get('loc'));
            self::assertSame(3, $repository->getSubject($subject)->get('ccn'));
        }
    }

    #[Test]
    public function itMergesPlainAndTypedCallableSubjectsIndependentlyOfRepositoryOrder(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $file = RelativePath::fromString('src/Service.php');
        $declaration = new DeclarationPath($symbol, $file, 420);
        $subject = MetricSubject::declaration($declaration);

        $plain = new InMemoryMetricRepository();
        $plain->addSubject($subject, MetricBag::fromArray(['loc' => 8]), $file, 0);

        $typed = new InMemoryMetricRepository();
        $typed->addCallable(new CallableWithMetrics(
            $declaration,
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
            MetricBag::fromArray(['ccn' => 3]),
            17,
        ));

        foreach ([$plain->mergeWith($typed), $typed->mergeWith($plain)] as $repository) {
            $callables = iterator_to_array($repository->allCallables(), false);
            self::assertCount(1, $callables);
            self::assertSame(CallableKind::Method, $callables[0]->callableKind);
            self::assertSame(17, $callables[0]->line);
            self::assertCount(1, iterator_to_array($repository->allLogicalClasses(), false));
            self::assertCount(2, $repository->forNamespace('App'));
            self::assertSame(8, $repository->getSubject($subject)->get('loc'));
            self::assertSame(3, $repository->getSubject($subject)->get('ccn'));
        }
    }

    #[Test]
    public function itRejectsConflictingTypedCallableMetadata(): void
    {
        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $file = RelativePath::fromString('src/Service.php');
        $declaration = new DeclarationPath($symbol, $file, 420);
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));

        $repository->addCallable(new CallableWithMetrics(
            $declaration,
            CallableKind::Method,
            null,
            null,
            $owner,
            new MetricBag(),
            17,
        ));

        $this->expectException(InvalidArgumentException::class);
        $repository->addCallable(new CallableWithMetrics(
            $declaration,
            CallableKind::Method,
            null,
            null,
            $owner,
            new MetricBag(),
            18,
        ));
    }

    #[Test]
    public function itKeepsLogicalClassProjectionsLocationFreeForExactClassDeclarationsInEitherMergeOrder(): void
    {
        $class = SymbolPath::forClass('App', 'Service');
        $firstPath = new DeclarationPath($class, RelativePath::fromString('src/A.php'), 100);
        $secondPath = new DeclarationPath($class, RelativePath::fromString('src/B.php'), 200);
        $firstSubject = MetricSubject::declaration($firstPath);
        $secondSubject = MetricSubject::declaration($secondPath);

        $first = new InMemoryMetricRepository();
        $first->addSubject($firstSubject, MetricBag::fromArray(['first' => 1]), $firstPath->file, 11);
        $this->assertLocationFreeLogicalClassProjection($first);

        $second = new InMemoryMetricRepository();
        $second->addSubject($secondSubject, MetricBag::fromArray(['second' => 2]), $secondPath->file, 22);

        foreach ([$first->mergeWith($second), $second->mergeWith($first)] as $repository) {
            $declarations = iterator_to_array($repository->allDeclarations(), false);
            self::assertCount(2, $declarations);
            $locations = [];
            foreach ($declarations as $declaration) {
                $locations[$declaration->file?->value() ?? ''] = $declaration->line;
            }
            ksort($locations);
            self::assertSame(['src/A.php' => 11, 'src/B.php' => 22], $locations);
            self::assertSame(1, $repository->getSubject($firstSubject)->get('first'));
            self::assertSame(2, $repository->getSubject($secondSubject)->get('second'));
            $this->assertLocationFreeLogicalClassProjection($repository);
        }
    }

    #[Test]
    public function itKeepsCallableOnlyOwnerProjectionsLocationFreeWithoutDuplicateIndexesInEitherMergeOrder(): void
    {
        $method = SymbolPath::forMethod('App', 'Service', 'run');
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $firstCallable = new CallableWithMetrics(
            new DeclarationPath($method, RelativePath::fromString('src/A.php'), 100),
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['ccn' => 3]),
            11,
        );
        $secondCallable = new CallableWithMetrics(
            new DeclarationPath($method, RelativePath::fromString('src/B.php'), 200),
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['ccn' => 5]),
            22,
        );

        $first = new InMemoryMetricRepository();
        $first->addCallable($firstCallable);
        $this->assertLocationFreeLogicalClassProjection($first);

        $second = new InMemoryMetricRepository();
        $second->addCallable($secondCallable);

        foreach ([$first->mergeWith($second), $second->mergeWith($first)] as $repository) {
            $callables = iterator_to_array($repository->allCallables(), false);
            self::assertCount(2, $callables);
            $locations = [];
            foreach ($callables as $callable) {
                $locations[$callable->file?->value() ?? ''] = $callable->line;
            }
            ksort($locations);
            self::assertSame(['src/A.php' => 11, 'src/B.php' => 22], $locations);
            self::assertCount(2, iterator_to_array($repository->allDeclarations(), false));
            self::assertCount(3, $repository->forNamespace('App'));
            self::assertSame(['App'], $repository->getNamespaces());
            $this->assertLocationFreeLogicalClassProjection($repository);
        }
    }

    #[Test]
    public function itProjectsTypedAggregateSubjectsToCanonicalPublicViews(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Service.php');
        $filePath = SymbolPath::forFile($file);
        $fileSubject = MetricSubject::aggregate($filePath);

        $repository->addSubject($fileSubject, (new MetricBag())->with('loc', 10)->withEntry('source', ['name' => 'first']), $file, 0);
        $repository->addSubject($fileSubject, (new MetricBag())->with('loc', 20)->withEntry('source', ['name' => 'second']), $file, 0);

        $fileMetrics = $repository->get($filePath);
        self::assertTrue($repository->has($filePath));
        self::assertSame(20, $fileMetrics->get('loc'));
        self::assertSame($fileMetrics->all(), $repository->getSubject($fileSubject)->all());
        self::assertSame($fileMetrics->entries('source'), $repository->getSubject($fileSubject)->entries('source'));
        self::assertCount(1, iterator_to_array($repository->all(SymbolType::File), false));

        $namespacePath = SymbolPath::forNamespace('App');
        $namespaceSubject = MetricSubject::aggregate($namespacePath);
        $repository->addSubject($namespaceSubject, MetricBag::fromArray(['loc.sum' => 20]), $file, 1);

        self::assertTrue($repository->has($namespacePath));
        self::assertSame(20, $repository->get($namespacePath)->get('loc.sum'));
        self::assertSame($repository->get($namespacePath)->all(), $repository->getSubject($namespaceSubject)->all());
        self::assertSame(['App'], $repository->getNamespaces());
        self::assertCount(1, $repository->forNamespace('App'));
        self::assertCount(1, iterator_to_array($repository->all(SymbolType::Namespace_), false));

        $projectPath = SymbolPath::forProject();
        $projectSubject = MetricSubject::aggregate($projectPath);
        $repository->addSubject($projectSubject, MetricBag::fromArray(['loc.sum' => 20]), null, null);

        self::assertTrue($repository->has($projectPath));
        self::assertSame(20, $repository->get($projectPath)->get('loc.sum'));
        self::assertSame($repository->get($projectPath)->all(), $repository->getSubject($projectSubject)->all());
        self::assertCount(1, iterator_to_array($repository->all(SymbolType::Project), false));
    }

    #[Test]
    public function itProjectsPlainAggregateWritesThroughTypedSubjectReads(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Service.php');
        $filePath = SymbolPath::forFile($file);
        $namespacePath = SymbolPath::forNamespace('App');
        $projectPath = SymbolPath::forProject();

        foreach ([$filePath, $namespacePath, $projectPath] as $path) {
            $repository->add($path, MetricBag::fromArray(['loc.sum' => 10]), $file, 0);
            $subject = MetricSubject::aggregate($path);

            self::assertTrue($repository->hasSubject($subject));
            self::assertSame($repository->get($path)->all(), $repository->getSubject($subject)->all());
        }

        self::assertCount(1, iterator_to_array($repository->all(SymbolType::File), false));
        self::assertCount(1, iterator_to_array($repository->all(SymbolType::Namespace_), false));
        self::assertCount(1, iterator_to_array($repository->all(SymbolType::Project), false));
        self::assertSame(['App'], $repository->getNamespaces());
        self::assertCount(1, $repository->forNamespace('App'));
    }

    #[Test]
    public function itKeepsAggregateSubjectReadsOnCanonicalStorageAfterPublicMutations(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Service.php');
        $path = SymbolPath::forNamespace('App');
        $subject = MetricSubject::aggregate($path);

        $repository->addSubject($subject, (new MetricBag())->with('loc.sum', 10)->withEntry('source', ['name' => 'typed']), null, null);
        $repository->addScalar($path, 'loc.sum', 20);
        $repository->add($path, (new MetricBag())->with('ccn.sum', 5)->withEntry('source', ['name' => 'plain']), $file, 13);
        $repository->addSubject($subject, (new MetricBag())->with('loc.sum', 30)->withEntry('source', ['name' => 'typed-again']), $file, 13);

        $public = $repository->get($path);
        self::assertSame(30, $public->get('loc.sum'));
        self::assertSame(5, $public->get('ccn.sum'));
        self::assertSame(
            [['name' => 'typed'], ['name' => 'plain'], ['name' => 'typed-again']],
            $public->entries('source'),
        );
        self::assertSame($public->all(), $repository->getSubject($subject)->all());
        self::assertSame($public->entries('source'), $repository->getSubject($subject)->entries('source'));

        $info = $repository->forNamespace('App')[0];
        self::assertSame($file, $info->file);
        self::assertSame(13, $info->line);
        self::assertCount(1, $repository->forNamespace('App'));
    }

    #[Test]
    public function itSynchronizesMixedPlainAndTypedAggregateMergesInBothOrders(): void
    {
        $path = SymbolPath::forNamespace('App');
        $subject = MetricSubject::aggregate($path);
        $plain = new InMemoryMetricRepository();
        $plainFile = RelativePath::fromString('src/Plain.php');
        $plain->add($path, (new MetricBag())->with('loc.sum', 10)->withEntry('source', ['name' => 'plain']), $plainFile, 13);
        $typed = new InMemoryMetricRepository();
        $typedFile = RelativePath::fromString('src/Typed.php');
        $typed->addSubject($subject, (new MetricBag())->with('loc.sum', 20)->withEntry('source', ['name' => 'typed']), $typedFile, null);

        foreach ([
            [$plain->mergeWith($typed), 20, [['name' => 'plain'], ['name' => 'typed']], $plainFile],
            [$typed->mergeWith($plain), 10, [['name' => 'typed'], ['name' => 'plain']], $typedFile],
        ] as [$repository, $loc, $entries, $expectedFile]) {
            $public = $repository->get($path);
            $typedMetrics = $repository->getSubject($subject);

            self::assertTrue($repository->has($path));
            self::assertSame($loc, $public->get('loc.sum'));
            self::assertSame($public->all(), $typedMetrics->all());
            self::assertSame($entries, $public->entries('source'));
            self::assertSame($public->entries('source'), $typedMetrics->entries('source'));
            self::assertCount(1, $repository->forNamespace('App'));
            self::assertCount(1, iterator_to_array($repository->all(SymbolType::Namespace_), false));
            self::assertSame($expectedFile, $repository->forNamespace('App')[0]->file);
            self::assertSame(13, $repository->forNamespace('App')[0]->line);
        }
    }

    private function assertLocationFreeLogicalClassProjection(InMemoryMetricRepository $repository): void
    {
        $logicalClasses = iterator_to_array($repository->allLogicalClasses(), false);
        self::assertCount(1, $logicalClasses);
        self::assertNull($logicalClasses[0]->file);
        self::assertNull($logicalClasses[0]->line);
    }

    private function addCallable(
        InMemoryMetricRepository $repository,
        SymbolPath $symbol,
        MetricBag $metrics,
        RelativePath $file,
        int $startFilePos,
    ): void {
        $repository->addCallable(new CallableWithMetrics(
            new DeclarationPath($symbol, $file, $startFilePos),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? '')),
            $metrics,
        ));
    }
}
