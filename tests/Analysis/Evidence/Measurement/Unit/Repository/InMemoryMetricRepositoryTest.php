<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Repository;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\AggregateMetricIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\LogicalClassMetricIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\NamespaceMetricIndex;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(InMemoryMetricRepository::class)]
#[CoversClass(AggregateMetricIndex::class)]
#[CoversClass(LogicalClassMetricIndex::class)]
#[CoversClass(NamespaceMetricIndex::class)]
final class InMemoryMetricRepositoryTest extends TestCase
{
    #[Test]
    public function itStoresAndRetrievesMetrics(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forMethod('App\\Service', 'UserService', 'calculate');
        $metrics = (new MetricBag())->with('complexity.ccn', 5);

        $this->addCallable($repository, $symbol, $metrics, RelativePath::fromString('src/Service/UserService.php'), 420);

        $declaration = DeclarationPath::of($symbol, RelativePath::fromString('src/Service/UserService.php'), DeclarationOrdinal::fromRank(1));
        $retrieved = $repository->getSubject(MetricSubject::declaration($declaration));

        self::assertInstanceOf(MetricBag::class, $retrieved); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(5, $retrieved->get('complexity.ccn'));
    }

    #[Test]
    public function itRefusesLogicalClassReadsEvenWhenTheClassIsUnknown(): void
    {
        $repository = new InMemoryMetricRepository();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Declaration metrics require an exact subject');

        $repository->get(SymbolPath::forClass('Unknown', 'Class'));
    }

    #[Test]
    public function itMergesMetricsForSameSymbol(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forNamespace('App\\Service');

        // First add
        $metrics1 = (new MetricBag())
            ->with('size.class-count.sum', 10)
            ->with('size.method-count', 50);
        $repository->add($symbol, $metrics1, RelativePath::fromString('src/Service/UserService.php'), 0);

        // Second add should merge
        $metrics2 = (new MetricBag())
            ->with('complexity.ccn.sum', 100)
            ->with('complexity.ccn.avg', 3.5);
        $repository->add($symbol, $metrics2, RelativePath::fromString('src/Service/UserService.php'), 0);

        $retrieved = $repository->get($symbol);

        self::assertInstanceOf(MetricBag::class, $retrieved); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(10, $retrieved->get('size.class-count.sum'));
        self::assertSame(50, $retrieved->get('size.method-count'));
        self::assertSame(100, $retrieved->get('complexity.ccn.sum'));
        self::assertSame(3.5, $retrieved->get('complexity.ccn.avg'));
    }

    #[Test]
    public function itChecksExistence(): void
    {
        $repository = new InMemoryMetricRepository();

        $existing = SymbolPath::forClass('App', 'Test');
        $existingSubject = self::classSubject($existing, RelativePath::fromString('test.php'));
        $repository->addSubject($existingSubject, new MetricBag(), RelativePath::fromString('test.php'), 1);

        self::assertTrue($repository->hasSubject($existingSubject));
        self::assertFalse($repository->hasSubject(self::classSubject(SymbolPath::forClass('Unknown', 'Class'), RelativePath::fromString('unknown.php'))));
    }

    #[Test]
    public function itPreservesExistingFileWhenMergingWithNullFile(): void
    {
        // Pins the CouplingCollector contract: graph-derived metric collectors call
        // ->add(symbol, metrics, null, …) on existing class/namespace symbols.
        // The repository must NOT overwrite the original SymbolInfo's file in that case;
        // otherwise downstream consumers (formatters, ranking) lose file association.
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('size.method-count', SymbolLevel::Class_),
            new MetricDefinition('coupling.cbo', SymbolLevel::Class_, classKeyScope: ClassKeyScope::LogicalName),
        ]);

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $originalFile = RelativePath::fromString('src/Service/UserService.php');
        $subject = self::classSubject($symbol, $originalFile);

        $repository->addSubject($subject, (new MetricBag())->with('size.method-count', 5), $originalFile, 10);
        // Graph-phase merge with no file context (CouplingCollector pattern).
        $repository->add($symbol, (new MetricBag())->with('coupling.cbo', 3), null, 0);

        $symbols = iterator_to_array($repository->allClassDeclarations(), false);

        self::assertCount(1, $symbols);
        self::assertNotNull($symbols[0]->file);
        self::assertTrue($symbols[0]->file->equals($originalFile));
        self::assertSame(10, $symbols[0]->line);

        $bag = $repository->getSubject($subject);
        self::assertSame(5, $bag->get('size.method-count'));
        self::assertSame(3, $bag->get('coupling.cbo'));
    }

    #[Test]
    public function itRegistersAnExtraDirectClassPublicationLevelWithItsDeclaredScope(): void
    {
        $definition = new MetricDefinition(
            'fixture.direct-class',
            SymbolLevel::Namespace_,
            classKeyScope: ClassKeyScope::Declaration,
            directPublicationLevels: [SymbolLevel::Class_],
        );
        $repository = new InMemoryMetricRepository([$definition]);
        $logical = SymbolPath::forClass('App', 'Exact');
        $file = RelativePath::fromString('src/Exact.php');
        $subject = self::classSubject($logical, $file);
        $repository->addSubject($subject, new MetricBag(), $file, 1);
        $repository->addSubjectScalar($subject, 'fixture.direct-class', 7);
        self::assertSame(7, $repository->getSubject($subject)->get('fixture.direct-class'));

        $this->expectException(LogicException::class);
        $repository->addSubjectScalar(MetricSubject::logicalClass(new LogicalClassPath($logical)), 'fixture.direct-class', 8);
    }

    #[Test]
    public function itRefusesAConflictingScopeForAnExtraDirectClassPublicationLevel(): void
    {
        $this->expectException(LogicException::class);
        new InMemoryMetricRepository([
            new MetricDefinition('fixture.direct-class', SymbolLevel::Class_, classKeyScope: ClassKeyScope::LogicalName),
            new MetricDefinition('fixture.direct-class', SymbolLevel::Namespace_, directPublicationLevels: [SymbolLevel::Class_]),
        ]);
    }

    #[Test]
    public function itIteratesOverCallables(): void
    {
        $repository = new InMemoryMetricRepository();

        $method1 = SymbolPath::forMethod('App', 'Service', 'method1');
        $method2 = SymbolPath::forMethod('App', 'Service', 'method2');
        $class = SymbolPath::forClass('App', 'Service');

        $this->addCallable($repository, $method1, new MetricBag(), RelativePath::fromString('test.php'), 100);
        $this->addCallable($repository, $method2, new MetricBag(), RelativePath::fromString('test.php'), 200);
        $repository->add($class, new MetricBag(), RelativePath::fromString('test.php'), 1);

        $callables = iterator_to_array($repository->all(SymbolLevel::Callable), false);

        self::assertCount(2, $callables);
    }

    /**
     * Losing this: the callable level query and `allCallables()` become two
     * spellings of one enumeration that are free to drift apart.
     */
    #[Test]
    public function itAnswersTheCallableLevelWithExactlyTheCallableEnumeration(): void
    {
        $repository = new InMemoryMetricRepository();

        $method = SymbolPath::forMethod('App', 'Service', 'method');
        $function = SymbolPath::forGlobalFunction('App', 'helper');
        $class = SymbolPath::forClass('App', 'Service');
        $namespace = SymbolPath::forNamespace('App');

        $this->addCallable($repository, $method, new MetricBag(), RelativePath::fromString('test.php'), 100);
        $this->addCallable($repository, $function, new MetricBag(), RelativePath::fromString('helpers.php'), 10);
        $repository->add($class, new MetricBag(), RelativePath::fromString('test.php'), 1);
        $repository->add($namespace, new MetricBag(), RelativePath::fromString('test.php'), 0);

        $byLevel = array_map(
            static fn(SymbolInfo $info): string => $info->symbolPath->toCanonical(),
            iterator_to_array($repository->all(SymbolLevel::Callable), false),
        );
        $byAccessor = array_map(
            static fn(SymbolInfo $info): string => $info->symbolPath->toCanonical(),
            iterator_to_array($repository->allCallables(), false),
        );

        self::assertSame($byAccessor, $byLevel);
        self::assertCount(2, $byLevel);
    }

    #[Test]
    public function itIteratesOverClasses(): void
    {
        $repository = new InMemoryMetricRepository();

        $method = SymbolPath::forMethod('App', 'Service', 'method');
        $class1 = SymbolPath::forClass('App', 'Service');
        $class2 = SymbolPath::forClass('App', 'Repository');

        $this->addCallable($repository, $method, new MetricBag(), RelativePath::fromString('test.php'), 100);
        $repository->addSubject(self::classSubject($class1, RelativePath::fromString('test.php')), new MetricBag(), RelativePath::fromString('test.php'), 1);
        $repository->addSubject(self::classSubject($class2, RelativePath::fromString('test2.php')), new MetricBag(), RelativePath::fromString('test2.php'), 1);

        $classes = iterator_to_array($repository->allClassDeclarations(), false);

        self::assertCount(2, $classes);
    }

    #[Test]
    public function itIteratesOverNamespaces(): void
    {
        $repository = new InMemoryMetricRepository();

        $ns1 = SymbolPath::forNamespace('App\\Service');
        $ns2 = SymbolPath::forNamespace('App\\Repository');
        $class = SymbolPath::forClass('App\\Service', 'Test');

        $ns1Metrics = (new MetricBag())->with('size.class-count.sum', 5);
        $repository->add($ns1, $ns1Metrics, RelativePath::fromString('test.php'), 0);

        $ns2Metrics = (new MetricBag())->with('size.class-count.sum', 3);
        $repository->add($ns2, $ns2Metrics, RelativePath::fromString('test2.php'), 0);

        $repository->add($class, new MetricBag(), RelativePath::fromString('test.php'), 1);

        $namespaces = iterator_to_array($repository->all(SymbolLevel::Namespace_), false);

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

        self::assertCount(3, $serviceSymbols);
        self::assertCount(1, array_filter($serviceSymbols, static fn(SymbolInfo $info): bool => $info->subject?->declarationPath()?->logical->getType() === \Qualimetrix\Core\Symbol\SymbolType::Class_));
    }

    #[Test]
    public function itMergesWithAnotherRepository(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $repo2 = new InMemoryMetricRepository();

        // Add to first repository
        $metrics1 = (new MetricBag())->with('complexity.ccn', 5);
        $this->addCallable(
            $repo1,
            SymbolPath::forMethod('App', 'ServiceA', 'method1'),
            $metrics1,
            RelativePath::fromString('ServiceA.php'),
            100,
        );

        // Add to second repository
        $metrics2 = (new MetricBag())->with('complexity.ccn', 10);
        $this->addCallable(
            $repo2,
            SymbolPath::forMethod('App', 'ServiceB', 'method2'),
            $metrics2,
            RelativePath::fromString('ServiceB.php'),
            200,
        );

        $merged = $repo1->mergedWith($repo2) ?? throw new LogicException('In-memory repositories must be merge-compatible');

        // Both symbols should exist in merged repository
        $first = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'ServiceA', 'method1'), RelativePath::fromString('ServiceA.php'), DeclarationOrdinal::fromRank(1)));
        $second = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'ServiceB', 'method2'), RelativePath::fromString('ServiceB.php'), DeclarationOrdinal::fromRank(1)));
        self::assertTrue($merged->hasSubject($first));
        self::assertTrue($merged->hasSubject($second));

        // Metrics should be correct
        self::assertSame(5, $merged->getSubject($first)->get('complexity.ccn'));
        self::assertSame(10, $merged->getSubject($second)->get('complexity.ccn'));

        // Original repositories should be unchanged
        self::assertFalse($repo1->hasSubject($second));
        self::assertFalse($repo2->hasSubject($first));
    }

    #[Test]
    public function itMergesOverlappingSymbols(): void
    {
        $repo1 = $this->declarationRepository(['size.method-count', 'size.class-loc', 'complexity.ccn.sum']);
        $repo2 = $this->declarationRepository(['size.method-count', 'size.class-loc', 'complexity.ccn.sum']);

        $symbol = SymbolPath::forClass('App', 'Service');
        $file = RelativePath::fromString('Service.php');
        $subject = self::classSubject($symbol, $file);

        // Add metrics to first repository
        $metrics1 = (new MetricBag())
            ->with('size.method-count', 5)
            ->with('size.class-loc', 100);
        $repo1->addSubject($subject, $metrics1, $file, 1);

        // Add different metrics to second repository for same symbol
        $metrics2 = (new MetricBag())
            ->with('complexity.ccn.sum', 25)
            ->with('size.class-loc', 150); // Override
        $repo2->addSubject($subject, $metrics2, $file, 1);

        $merged = $repo1->mergedWith($repo2) ?? throw new LogicException('In-memory repositories must be merge-compatible');

        $result = $merged->getSubject($subject);

        // Metrics from both should be present, with second overriding duplicates
        self::assertSame(5, $result->get('size.method-count')); // From repo1
        self::assertSame(25, $result->get('complexity.ccn.sum')); // From repo2
        self::assertSame(150, $result->get('size.class-loc')); // Overridden by repo2
    }

    #[Test]
    public function itMergesWithEmptyRepository(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $repo2 = new InMemoryMetricRepository();

        $metrics = (new MetricBag())->with('complexity.ccn', 5);
        $this->addCallable(
            $repo1,
            SymbolPath::forMethod('App', 'Service', 'method'),
            $metrics,
            RelativePath::fromString('Service.php'),
            100,
        );

        // Merge with empty
        $merged = $repo1->mergedWith($repo2) ?? throw new LogicException('In-memory repositories must be merge-compatible');

        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'method'), RelativePath::fromString('Service.php'), DeclarationOrdinal::fromRank(1)));
        self::assertTrue($merged->hasSubject($subject));
        self::assertSame(5, $merged->getSubject($subject)->get('complexity.ccn'));
    }

    #[Test]
    public function itUpdatesLineFromZeroToPositiveOnSubsequentAdd(): void
    {
        $repository = $this->declarationRepository(['complexity.wmc', 'size.class-loc']);
        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $file = RelativePath::fromString('src/Service/UserService.php');
        $subject = self::classSubject($symbol, $file);

        // First add with line=0 (e.g., from aggregator)
        $repository->addSubject($subject, (new MetricBag())->with('complexity.wmc', 10), $file, 0);

        // Second add with real line number
        $repository->addSubject($subject, (new MetricBag())->with('size.class-loc', 100), $file, 42);

        $infos = iterator_to_array($repository->allClassDeclarations(), false);
        $info = $infos[0];

        self::assertSame(42, $info->line);
    }

    #[Test]
    public function itKeepsPositiveLineWhenSubsequentAddHasZero(): void
    {
        $repository = $this->declarationRepository(['complexity.wmc', 'size.class-loc']);
        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $file = RelativePath::fromString('src/Service/UserService.php');
        $subject = self::classSubject($symbol, $file);

        // First add with real line number
        $repository->addSubject($subject, (new MetricBag())->with('size.class-loc', 100), $file, 42);

        // Second add with line=0 should NOT overwrite
        $repository->addSubject($subject, (new MetricBag())->with('complexity.wmc', 10), $file, 0);

        $infos = iterator_to_array($repository->allClassDeclarations(), false);
        $info = $infos[0];

        self::assertSame(42, $info->line);
    }

    #[Test]
    public function itUpdatesLineFromZeroToPositiveDuringMergeWith(): void
    {
        $repo1 = $this->declarationRepository(['complexity.wmc', 'size.class-loc']);
        $repo2 = $this->declarationRepository(['complexity.wmc', 'size.class-loc']);

        $symbol = SymbolPath::forClass('App', 'Service');
        $file = RelativePath::fromString('Service.php');
        $subject = self::classSubject($symbol, $file);

        // repo1 has line=0
        $repo1->addSubject($subject, (new MetricBag())->with('complexity.wmc', 10), $file, 0);

        // repo2 has line=42
        $repo2->addSubject($subject, (new MetricBag())->with('size.class-loc', 100), $file, 42);

        $merged = $repo1->mergedWith($repo2) ?? throw new LogicException('In-memory repositories must be merge-compatible');

        $infos = iterator_to_array($merged->allClassDeclarations(), false);
        $info = $infos[0];

        self::assertSame(42, $info->line);
    }

    #[Test]
    public function itAddScalarDoesNotDuplicateDataBagEntries(): void
    {
        $repository = $this->declarationRepository(['complexity.ccn', 'size.class-loc']);

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $file = RelativePath::fromString('src/Service/UserService.php');
        $subject = self::classSubject($symbol, $file);
        $metrics = (new MetricBag())
            ->with('complexity.ccn', 5)
            ->withEntry('dependencies', ['name' => 'Foo'])
            ->withEntry('dependencies', ['name' => 'Bar']);

        $repository->addSubject($subject, $metrics, $file, 1);

        $repository->addSubjectScalar($subject, 'size.class-loc', 100);

        $retrieved = $repository->getSubject($subject);

        self::assertSame(2, $retrieved->entryCount('dependencies'));
    }

    #[Test]
    public function itAddScalarIgnoresNonExistentSymbol(): void
    {
        $repository = new InMemoryMetricRepository();

        $symbol = SymbolPath::forNamespace('App\\Service');

        $repository->addScalar($symbol, 'complexity.ccn', 10);

        self::assertFalse($repository->has($symbol));
    }

    #[Test]
    public function itAddScalarUpdatesExistingMetric(): void
    {
        $repository = $this->declarationRepository(['foo', 'bar']);

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $file = RelativePath::fromString('src/Service/UserService.php');
        $subject = self::classSubject($symbol, $file);
        $metrics = (new MetricBag())
            ->with('foo', 10)
            ->with('bar', 42);

        $repository->addSubject($subject, $metrics, $file, 1);

        $repository->addSubjectScalar($subject, 'foo', 20);

        $retrieved = $repository->getSubject($subject);

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
        $declaration = DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0));
        $subject = MetricSubject::declaration($declaration);
        $callable = new CallableWithMetrics(
            $declaration,
            0,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $declaration->file, DeclarationOrdinal::fromRank(0)),
            MetricBag::fromArray(['complexity.ccn' => 3]),
            17,
        );

        $subjectFirst = new InMemoryMetricRepository();
        $subjectFirst->addSubject($subject, MetricBag::fromArray(['size.loc' => 8]), $file, 17);
        $subjectFirst->addCallable($callable);

        $callableFirst = new InMemoryMetricRepository();
        $callableFirst->addCallable($callable);
        $callableFirst->addSubject($subject, MetricBag::fromArray(['size.loc' => 8]), $file, 17);

        foreach ([$subjectFirst, $callableFirst] as $repository) {
            $callables = iterator_to_array($repository->allCallables(), false);
            self::assertCount(1, $callables);
            self::assertSame(CallableKind::Method, $callables[0]->callableKind);
            self::assertSame(17, $callables[0]->line);
            self::assertCount(1, iterator_to_array($repository->allDeclarations(), false));
            self::assertCount(1, iterator_to_array($repository->allLogicalClasses(), false));
            self::assertCount(2, $repository->forNamespace('App'));
            self::assertSame(8, $repository->getSubject($subject)->get('size.loc'));
            self::assertSame(3, $repository->getSubject($subject)->get('complexity.ccn'));
        }
    }

    #[Test]
    public function itMergesPlainAndTypedCallableSubjectsIndependentlyOfRepositoryOrder(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $file = RelativePath::fromString('src/Service.php');
        $declaration = DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0));
        $subject = MetricSubject::declaration($declaration);

        $plain = new InMemoryMetricRepository();
        $plain->addSubject($subject, MetricBag::fromArray(['size.loc' => 8]), $file, 0);

        $typed = new InMemoryMetricRepository();
        $typed->addCallable(new CallableWithMetrics(
            $declaration,
            0,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $declaration->file, DeclarationOrdinal::fromRank(0)),
            MetricBag::fromArray(['complexity.ccn' => 3]),
            17,
        ));

        foreach ([($plain->mergedWith($typed) ?? throw new LogicException('In-memory repositories must be merge-compatible')), ($typed->mergedWith($plain) ?? throw new LogicException('In-memory repositories must be merge-compatible'))] as $repository) {
            $callables = iterator_to_array($repository->allCallables(), false);
            self::assertCount(1, $callables);
            self::assertSame(CallableKind::Method, $callables[0]->callableKind);
            self::assertSame(17, $callables[0]->line);
            self::assertCount(1, iterator_to_array($repository->allLogicalClasses(), false));
            self::assertCount(2, $repository->forNamespace('App'));
            self::assertSame(8, $repository->getSubject($subject)->get('size.loc'));
            self::assertSame(3, $repository->getSubject($subject)->get('complexity.ccn'));
        }
    }

    #[Test]
    public function itRejectsConflictingTypedCallableMetadata(): void
    {
        $repository = new InMemoryMetricRepository();
        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $file = RelativePath::fromString('src/Service.php');
        $declaration = DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));

        $repository->addCallable(new CallableWithMetrics(
            $declaration,
            0,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of($owner->symbolPath, $declaration->file, DeclarationOrdinal::fromRank(0)),
            new MetricBag(),
            17,
        ));

        $this->expectException(InvalidArgumentException::class);
        $repository->addCallable(new CallableWithMetrics(
            $declaration,
            0,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of($owner->symbolPath, $declaration->file, DeclarationOrdinal::fromRank(0)),
            new MetricBag(),
            18,
        ));
    }

    #[Test]
    public function itKeepsLogicalClassProjectionsLocationFreeForExactClassDeclarationsInEitherMergeOrder(): void
    {
        $class = SymbolPath::forClass('App', 'Service');
        $firstPath = DeclarationPath::of($class, RelativePath::fromString('src/A.php'), DeclarationOrdinal::fromRank(0));
        $secondPath = DeclarationPath::of($class, RelativePath::fromString('src/B.php'), DeclarationOrdinal::fromRank(0));
        $firstSubject = MetricSubject::declaration($firstPath);
        $secondSubject = MetricSubject::declaration($secondPath);

        $first = $this->declarationRepository(['first', 'second']);
        $first->addSubject($firstSubject, MetricBag::fromArray(['first' => 1]), $firstPath->file, 11);
        $this->assertLocationFreeLogicalClassProjection($first);

        $second = $this->declarationRepository(['first', 'second']);
        $second->addSubject($secondSubject, MetricBag::fromArray(['second' => 2]), $secondPath->file, 22);

        foreach ([($first->mergedWith($second) ?? throw new LogicException('In-memory repositories must be merge-compatible')), ($second->mergedWith($first) ?? throw new LogicException('In-memory repositories must be merge-compatible'))] as $repository) {
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
    public function itPublishesFoldedClassAndNamespaceEvidenceWithoutCollapsingDeclarations(): void
    {
        $first = $this->declarationRepository(['first', 'second']);
        $firstPath = DeclarationPath::of(
            SymbolPath::forClass('App', 'Service'),
            RelativePath::fromString('src/First.php'),
            DeclarationOrdinal::fromRank(0),
        );
        $first->addSubject(MetricSubject::declaration($firstPath), MetricBag::fromArray(['first' => 1]), $firstPath->file, 1);

        $second = $this->declarationRepository(['first', 'second']);
        $secondPath = DeclarationPath::of(
            SymbolPath::forClass('app', 'service'),
            RelativePath::fromString('src/Second.php'),
            DeclarationOrdinal::fromRank(0),
        );
        $second->addSubject(MetricSubject::declaration($secondPath), MetricBag::fromArray(['second' => 2]), $secondPath->file, 2);

        $repository = $second->mergedWith($first) ?? throw new LogicException('In-memory repositories must be merge-compatible');

        self::assertCount(2, iterator_to_array($repository->allDeclarations(), false));
        self::assertCount(1, iterator_to_array($repository->allLogicalClasses(), false));
        self::assertSame(['App'], $repository->getNamespaces());
        self::assertSame(['class', 'namespace'], array_column($repository->mixedSpellings(), 'kind'));
        self::assertSame('App\\Service', $repository->mixedSpellings()[0]->canonical);
        self::assertSame('App', $repository->mixedSpellings()[1]->canonical);
    }

    #[Test]
    public function itPreservesLogicalOnlySpellingEvidenceAcrossRepeatedMerges(): void
    {
        $mixed = $this->logicalRepository(['first', 'second', 'other']);
        $mixed->add(SymbolPath::forNamespace('app'), MetricBag::fromArray(['namespace' => 4]), null, null);
        $mixed->add(SymbolPath::fromClassFqn('app\\service'), MetricBag::fromArray(['second' => 2]), null, null);
        $mixed->add(SymbolPath::fromClassFqn('App\\Service'), MetricBag::fromArray(['first' => 1]), null, null);
        self::assertCount(1, array_filter(
            $mixed->forNamespace('APP'),
            static fn(SymbolInfo $info): bool => $info->subject?->logicalClassPath() !== null,
        ));
        self::assertSame(4, $mixed->get(SymbolPath::forNamespace('APP'))->get('namespace'));
        $other = $this->logicalRepository(['first', 'second', 'other']);
        $other->add(SymbolPath::fromClassFqn('Other\\Thing'), MetricBag::fromArray(['other' => 3]), null, null);

        $repository = $mixed->mergedWith($other)
            ?? throw new LogicException('In-memory repositories must be merge-compatible');
        $repository = $repository->mergedWith(new InMemoryMetricRepository())
            ?? throw new LogicException('In-memory repositories must be merge-compatible');

        self::assertSame(['class', 'namespace'], array_column($repository->mixedSpellings(), 'kind'));
        self::assertSame(['App', 'Other'], $repository->getNamespaces());
        self::assertCount(1, array_filter(
            $repository->forNamespace('APP'),
            static fn(SymbolInfo $info): bool => $info->subject?->logicalClassPath() !== null,
        ));
        self::assertSame(4, $repository->get(SymbolPath::forNamespace('APP'))->get('namespace'));
        self::assertSame(1, $repository->getSubject(MetricSubject::logicalClass(new LogicalClassPath(SymbolPath::fromClassFqn('APP\\SERVICE'))))->get('first'));
        self::assertSame(2, $repository->getSubject(MetricSubject::logicalClass(new LogicalClassPath(SymbolPath::fromClassFqn('APP\\SERVICE'))))->get('second'));
        self::assertSame(3, $repository->getSubject(MetricSubject::logicalClass(new LogicalClassPath(SymbolPath::fromClassFqn('OTHER\\THING'))))->get('other'));
    }

    #[Test]
    public function itRekeysANamespaceAggregateWhenAnExactClassChangesItsCanonicalSpelling(): void
    {
        $repository = $this->declarationRepository(['size.class-loc']);
        $lower = SymbolPath::forNamespace('App\\foo');
        $repository->add($lower, MetricBag::fromArray(['size.loc.sum' => 17]), null, null);
        $class = SymbolPath::fromClassFqn('App\\Foo\\Example');
        $declaration = DeclarationPath::of(
            $class,
            RelativePath::fromString('src/Example.php'),
            DeclarationOrdinal::fromRank(0),
        );

        $repository->addSubject(
            MetricSubject::declaration($declaration),
            MetricBag::fromArray(['size.class-loc' => 5]),
            $declaration->file,
            3,
        );

        self::assertSame(['App\\Foo'], $repository->getNamespaces());
        self::assertSame(17, $repository->get($lower)->get('size.loc.sum'));
        self::assertSame(17, $repository->get(SymbolPath::forNamespace('App\\Foo'))->get('size.loc.sum'));
    }

    #[Test]
    public function itRekeysANamespaceAggregateWhenALogicalClassChangesItsCanonicalSpelling(): void
    {
        $repository = $this->logicalRepository(['coupling.cbo']);
        $lower = SymbolPath::forNamespace('App\\foo');
        $repository->add($lower, MetricBag::fromArray(['size.loc.sum' => 17]), null, null);

        $repository->addSubject(
            MetricSubject::logicalClass(new LogicalClassPath(SymbolPath::forClass('App\\Foo', 'Example'))),
            MetricBag::fromArray(['coupling.cbo' => 5]),
            null,
            0,
        );

        self::assertSame(['App\\Foo'], $repository->getNamespaces());
        self::assertSame(17, $repository->get($lower)->get('size.loc.sum'));
        self::assertSame(17, $repository->get(SymbolPath::forNamespace('App\\Foo'))->get('size.loc.sum'));
    }

    #[Test]
    public function itRekeysANamespaceAggregateWhenACallableOwnerChangesItsCanonicalSpelling(): void
    {
        $repository = new InMemoryMetricRepository();
        $lower = SymbolPath::forNamespace('App\\foo');
        $repository->add($lower, MetricBag::fromArray(['size.loc.sum' => 19]), null, null);
        $method = SymbolPath::forMethod('App\\Foo', 'Example', 'run');

        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of($method, RelativePath::fromString('src/Example.php'), DeclarationOrdinal::fromRank(0)),
            12,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of(SymbolPath::forClass('App\\Foo', 'Example'), DeclarationPath::of($method, RelativePath::fromString('src/Example.php'), DeclarationOrdinal::fromRank(0))->file, DeclarationOrdinal::fromRank(0)),
            MetricBag::fromArray(['complexity.ccn' => 2]),
            4,
        ));

        self::assertSame(['App\\Foo'], $repository->getNamespaces());
        self::assertSame(19, $repository->get($lower)->get('size.loc.sum'));
        self::assertSame(19, $repository->get(SymbolPath::forNamespace('App\\Foo'))->get('size.loc.sum'));
    }

    #[Test]
    public function itObservesAuthoredNamespaceSpellingsBeforeTypedAggregateCanonicalization(): void
    {
        $repository = new InMemoryMetricRepository();
        $lower = SymbolPath::forNamespace('App\\foo');
        $upper = SymbolPath::forNamespace('App\\Foo');
        $repository->addSubject(
            MetricSubject::aggregate($lower),
            MetricBag::fromArray(['size.loc.sum' => 17]),
            null,
            null,
        );

        $repository->addSubject(
            MetricSubject::aggregate($upper),
            MetricBag::fromArray(['size.class-count.sum' => 2]),
            null,
            null,
        );

        self::assertSame(['App\\Foo'], $repository->getNamespaces());
        self::assertSame(17, $repository->get($lower)->get('size.loc.sum'));
        self::assertSame(2, $repository->get($upper)->get('size.class-count.sum'));
    }

    #[Test]
    #[DataProvider('provideGlobalFunctionWriteForms')]
    public function itRekeysNamespaceBagsWhenAGlobalFunctionChangesCanonicalSpelling(bool $typed): void
    {
        $repository = new InMemoryMetricRepository();
        $lower = SymbolPath::forNamespace('app');
        $upper = SymbolPath::forNamespace('App');
        $repository->add($lower, (new MetricBag())->with('first', 7)->withEntry('source', ['name' => 'first']), null, null);
        $declarations = [];
        foreach (['app' => 'first', 'App' => 'second'] as $namespace => $name) {
            $declaration = DeclarationPath::of(
                SymbolPath::forGlobalFunction($namespace, $name),
                RelativePath::fromString('src/' . $name . '.php'),
                DeclarationOrdinal::fromRank(0),
            );
            $declarations[] = $declaration;
            $metrics = MetricBag::fromArray(['complexity.ccn' => 2]);
            if ($typed) {
                $repository->addCallable(new CallableWithMetrics($declaration, 10, CallableKind::Function, null, null, null, $metrics, 3));
            } else {
                $repository->addSubject(MetricSubject::declaration($declaration), $metrics, $declaration->file, 3);
            }
        }
        self::assertSame('App', $repository->forNamespace('APP')[0]->symbolPath->namespace);
        self::assertSame(7, $repository->get($upper)->get('first'));
        $repository->add($upper, (new MetricBag())->with('second', 9)->withEntry('source', ['name' => 'second']), null, null);
        $repository->addScalar($lower, 'computed', 11);

        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::Namespace_), false));
        foreach ([$lower, $upper, SymbolPath::forNamespace('APP')] as $namespace) {
            $bag = $repository->get($namespace);
            self::assertSame(['first' => 7, 'second' => 9, 'computed' => 11], $bag->all());
            self::assertSame([['name' => 'first'], ['name' => 'second']], $bag->entries('source'));
        }
        self::assertSame(['App'], $repository->getNamespaces());
        self::assertCount(3, $repository->forNamespace('APP'));
        self::assertSame(['app', 'App'], array_map(
            static fn(SymbolInfo $info): ?string => $info->symbolPath->namespace,
            iterator_to_array($repository->allDeclarations(), false),
        ));
        foreach ($declarations as $declaration) {
            self::assertSame(2, $repository->getSubject(MetricSubject::declaration($declaration))->get('complexity.ccn'));
        }
        self::assertCount(1, $repository->mixedSpellings());
        self::assertSame(['App', 'app'], $repository->mixedSpellings()[0]->spellings);
    }

    /** @return iterable<string, array{bool}> */
    public static function provideGlobalFunctionWriteForms(): iterable
    {
        yield 'typed callable' => [true];
        yield 'exact subject' => [false];
    }

    #[Test]
    public function itMergesCaseVariantNamespaceBagsWithoutLosingValuesInEitherOrder(): void
    {
        $lower = new InMemoryMetricRepository();
        $lowerPath = SymbolPath::forNamespace('app');
        $lowerFile = RelativePath::fromString('src/Lower.php');
        $lower->add($lowerPath, (new MetricBag())->with('first', 7)->with('shared', 10)->withEntry('source', ['name' => 'lower']), $lowerFile, 3);
        $upper = new InMemoryMetricRepository();
        $upperPath = SymbolPath::forNamespace('App');
        $upperFile = RelativePath::fromString('src/Upper.php');
        $upper->addSubject(MetricSubject::aggregate($upperPath), (new MetricBag())->with('second', 9)->with('shared', 20)->withEntry('source', ['name' => 'upper']), $upperFile, 5);

        $filePath = SymbolPath::forFile($lowerFile);
        $lower->add($filePath, MetricBag::fromArray(['size.loc' => 2]), $lowerFile, 1);
        $upper->add(SymbolPath::forProject(), MetricBag::fromArray(['size.loc.sum' => 4]), null, null);

        foreach ([
            [$lower, $upper, 20, [['name' => 'lower'], ['name' => 'upper']], $lowerFile, 3],
            [$upper, $lower, 10, [['name' => 'upper'], ['name' => 'lower']], $upperFile, 5],
        ] as [$left, $right, $shared, $entries, $file, $line]) {
            $repository = $left->mergedWith($right) ?? throw new LogicException('In-memory repositories must be merge-compatible');
            $repository = $repository->mergedWith(new InMemoryMetricRepository()) ?? throw new LogicException('In-memory repositories must be merge-compatible');

            $infos = iterator_to_array($repository->all(SymbolLevel::Namespace_), false);
            self::assertCount(1, $infos);
            self::assertSame('App', $infos[0]->symbolPath->namespace);
            self::assertNull($infos[0]->file);
            self::assertNull($infos[0]->line);
            foreach ([$lowerPath, $upperPath, SymbolPath::forNamespace('APP')] as $path) {
                $bag = $repository->get($path);
                self::assertSame(7, $bag->get('first'));
                self::assertSame(9, $bag->get('second'));
                self::assertSame($shared, $bag->get('shared'));
                self::assertSame($entries, $bag->entries('source'));
                self::assertSame($bag->all(), $repository->getSubject(MetricSubject::aggregate($path))->all());
            }
            self::assertSame(['App'], $repository->getNamespaces());
            self::assertCount(1, $repository->forNamespace('APP'));
            self::assertSame(['App', 'app'], $repository->mixedSpellings()[0]->spellings);
            self::assertSame(2, $repository->get($filePath)->get('size.loc'));
            self::assertSame(4, $repository->get(SymbolPath::forProject())->get('size.loc.sum'));
        }
        self::assertSame(10, $lower->get($lowerPath)->get('shared'));
        self::assertSame(20, $upper->get($upperPath)->get('shared'));
    }

    #[Test]
    public function itKeepsCallableOnlyOwnerProjectionsLocationFreeWithoutDuplicateIndexesInEitherMergeOrder(): void
    {
        $method = SymbolPath::forMethod('App', 'Service', 'run');
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $firstCallable = new CallableWithMetrics(
            DeclarationPath::of($method, RelativePath::fromString('src/A.php'), DeclarationOrdinal::fromRank(0)),
            100,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of($owner->symbolPath, DeclarationPath::of($method, RelativePath::fromString('src/A.php'), DeclarationOrdinal::fromRank(0))->file, DeclarationOrdinal::fromRank(0)),
            MetricBag::fromArray(['complexity.ccn' => 3]),
            11,
        );
        $secondCallable = new CallableWithMetrics(
            DeclarationPath::of($method, RelativePath::fromString('src/B.php'), DeclarationOrdinal::fromRank(0)),
            200,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of($owner->symbolPath, DeclarationPath::of($method, RelativePath::fromString('src/B.php'), DeclarationOrdinal::fromRank(0))->file, DeclarationOrdinal::fromRank(0)),
            MetricBag::fromArray(['complexity.ccn' => 5]),
            22,
        );

        $first = new InMemoryMetricRepository();
        $first->addCallable($firstCallable);
        $this->assertLocationFreeLogicalClassProjection($first);

        $second = new InMemoryMetricRepository();
        $second->addCallable($secondCallable);

        foreach ([($first->mergedWith($second) ?? throw new LogicException('In-memory repositories must be merge-compatible')), ($second->mergedWith($first) ?? throw new LogicException('In-memory repositories must be merge-compatible'))] as $repository) {
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

        $repository->addSubject($fileSubject, (new MetricBag())->with('size.loc', 10)->withEntry('source', ['name' => 'first']), $file, 0);
        $repository->addSubject($fileSubject, (new MetricBag())->with('size.loc', 20)->withEntry('source', ['name' => 'second']), $file, 0);

        $fileMetrics = $repository->get($filePath);
        self::assertTrue($repository->has($filePath));
        self::assertSame(20, $fileMetrics->get('size.loc'));
        self::assertSame($fileMetrics->all(), $repository->getSubject($fileSubject)->all());
        self::assertSame($fileMetrics->entries('source'), $repository->getSubject($fileSubject)->entries('source'));
        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::File), false));

        $namespacePath = SymbolPath::forNamespace('App');
        $namespaceSubject = MetricSubject::aggregate($namespacePath);
        $repository->addSubject($namespaceSubject, MetricBag::fromArray(['size.loc.sum' => 20]), $file, 1);

        self::assertTrue($repository->has($namespacePath));
        self::assertSame(20, $repository->get($namespacePath)->get('size.loc.sum'));
        self::assertSame($repository->get($namespacePath)->all(), $repository->getSubject($namespaceSubject)->all());
        self::assertSame(['App'], $repository->getNamespaces());
        self::assertCount(1, $repository->forNamespace('App'));
        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::Namespace_), false));

        $projectPath = SymbolPath::forProject();
        $projectSubject = MetricSubject::aggregate($projectPath);
        $repository->addSubject($projectSubject, MetricBag::fromArray(['size.loc.sum' => 20]), null, null);

        self::assertTrue($repository->has($projectPath));
        self::assertSame(20, $repository->get($projectPath)->get('size.loc.sum'));
        self::assertSame($repository->get($projectPath)->all(), $repository->getSubject($projectSubject)->all());
        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::Project), false));
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
            $repository->add($path, MetricBag::fromArray(['size.loc.sum' => 10]), $file, 0);
            $subject = MetricSubject::aggregate($path);

            self::assertTrue($repository->hasSubject($subject));
            self::assertSame($repository->get($path)->all(), $repository->getSubject($subject)->all());
        }

        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::File), false));
        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::Namespace_), false));
        self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::Project), false));
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

        $repository->addSubject($subject, (new MetricBag())->with('size.loc.sum', 10)->withEntry('source', ['name' => 'typed']), null, null);
        $repository->addScalar($path, 'size.loc.sum', 20);
        $repository->add($path, (new MetricBag())->with('complexity.ccn.sum', 5)->withEntry('source', ['name' => 'plain']), $file, 13);
        $repository->addSubject($subject, (new MetricBag())->with('size.loc.sum', 30)->withEntry('source', ['name' => 'typed-again']), $file, 13);

        $public = $repository->get($path);
        self::assertSame(30, $public->get('size.loc.sum'));
        self::assertSame(5, $public->get('complexity.ccn.sum'));
        self::assertSame(
            [['name' => 'typed'], ['name' => 'plain'], ['name' => 'typed-again']],
            $public->entries('source'),
        );
        self::assertSame($public->all(), $repository->getSubject($subject)->all());
        self::assertSame($public->entries('source'), $repository->getSubject($subject)->entries('source'));

        $info = $repository->forNamespace('App')[0];
        self::assertNull($info->file);
        self::assertNull($info->line);
        self::assertCount(1, $repository->forNamespace('App'));
    }

    #[Test]
    public function itSynchronizesMixedPlainAndTypedAggregateMergesInBothOrders(): void
    {
        $path = SymbolPath::forNamespace('App');
        $subject = MetricSubject::aggregate($path);
        $plain = new InMemoryMetricRepository();
        $plainFile = RelativePath::fromString('src/Plain.php');
        $plain->add($path, (new MetricBag())->with('size.loc.sum', 10)->withEntry('source', ['name' => 'plain']), $plainFile, 13);
        $typed = new InMemoryMetricRepository();
        $typedFile = RelativePath::fromString('src/Typed.php');
        $typed->addSubject($subject, (new MetricBag())->with('size.loc.sum', 20)->withEntry('source', ['name' => 'typed']), $typedFile, null);

        foreach ([
            [($plain->mergedWith($typed) ?? throw new LogicException('In-memory repositories must be merge-compatible')), 20, [['name' => 'plain'], ['name' => 'typed']], $plainFile],
            [($typed->mergedWith($plain) ?? throw new LogicException('In-memory repositories must be merge-compatible')), 10, [['name' => 'typed'], ['name' => 'plain']], $typedFile],
        ] as [$repository, $loc, $entries, $expectedFile]) {
            $public = $repository->get($path);
            $typedMetrics = $repository->getSubject($subject);

            self::assertTrue($repository->has($path));
            self::assertSame($loc, $public->get('size.loc.sum'));
            self::assertSame($public->all(), $typedMetrics->all());
            self::assertSame($entries, $public->entries('source'));
            self::assertSame($public->entries('source'), $typedMetrics->entries('source'));
            self::assertCount(1, $repository->forNamespace('App'));
            self::assertCount(1, iterator_to_array($repository->all(SymbolLevel::Namespace_), false));
            self::assertNull($repository->forNamespace('App')[0]->file);
            self::assertNull($repository->forNamespace('App')[0]->line);
        }
    }

    /** @return iterable<string, array{SymbolPath}> */
    public static function scalarDeclarationSymbols(): iterable
    {
        yield 'class' => [SymbolPath::forClass('App', 'Absent')];
        yield 'method' => [SymbolPath::forMethod('App', 'Absent', 'run')];
        yield 'function' => [SymbolPath::forGlobalFunction('App', 'absent')];
    }

    #[Test]
    #[DataProvider('scalarDeclarationSymbols')]
    public function itRefusesScalarWritesWithoutAnExactDeclaration(SymbolPath $logical): void
    {
        self::expectException(LogicException::class);
        (new InMemoryMetricRepository())->addScalar($logical, 'value', 7);
    }

    #[Test]
    #[DataProvider('scalarDeclarationSymbols')]
    public function itRefusesScalarWritesToMissingExactDeclarations(SymbolPath $logical): void
    {
        $subject = MetricSubject::declaration(DeclarationPath::of(
            $logical,
            RelativePath::fromString('src/Absent.php'),
            DeclarationOrdinal::fromRank(0),
        ));
        self::expectException(LogicException::class);
        $this->declarationRepository(['value'])->addSubjectScalar($subject, 'value', 7);
    }

    #[Test]
    public function itAddsOneScalarOnlyToAnExistingExactClassDeclaration(): void
    {
        $repository = $this->declarationRepository(['size.class-loc', 'design.dit']);
        $logical = SymbolPath::forClass('App', 'Shim');
        $declaration = DeclarationPath::of($logical, RelativePath::fromString('src/Shim.php'), DeclarationOrdinal::fromRank(0));
        $subject = MetricSubject::declaration($declaration);
        $repository->addSubject($subject, (new MetricBag())->with('size.class-loc', 12), $declaration->file, 3);

        $repository->addSubjectScalar($subject, 'design.dit', 2);

        self::assertSame(2, $repository->getSubject($subject)->get('design.dit'));
        self::assertSame(12, $repository->getSubject($subject)->get('size.class-loc'));
        self::assertNull($repository->getSubject(MetricSubject::logicalClass(new LogicalClassPath($logical)))->get('design.dit'));
    }

    #[Test]
    public function itRefusesAClassScalarWithoutAnExistingDeclaration(): void
    {
        $repository = $this->declarationRepository(['design.dit']);
        $declaration = DeclarationPath::of(
            SymbolPath::forClass('App', 'Absent'),
            RelativePath::fromString('src/Absent.php'),
            DeclarationOrdinal::fromRank(0),
        );
        $subject = MetricSubject::declaration($declaration);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Scalar requires an existing exact declaration');
        $repository->addSubjectScalar($subject, 'design.dit', 7);
    }

    #[Test]
    public function itAddsOneScalarToACallableDeclarationWithoutTouchingAClassProjection(): void
    {
        $repository = new InMemoryMetricRepository();
        $method = SymbolPath::forMethod('App', 'Service', 'handle');
        $declaration = DeclarationPath::of($method, RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0));
        $subject = MetricSubject::declaration($declaration);
        $repository->addSubject($subject, (new MetricBag())->with('complexity.ccn', 3), $declaration->file, 10);

        $repository->addSubjectScalar($subject, 'complexity.cognitive', 5);

        // A callable has no logical-class projection to refresh, so the write
        // must stop at its own subject.
        self::assertSame(5, $repository->getSubject($subject)->get('complexity.cognitive'));
        self::assertSame(3, $repository->getSubject($subject)->get('complexity.ccn'));
        self::assertNull($repository->getSubject(MetricSubject::logicalClass(new LogicalClassPath(SymbolPath::forClass('App', 'Service'))))->get('complexity.cognitive'));
    }

    #[Test]
    public function itRoutesAnAggregateSubjectScalarToItsAggregatePath(): void
    {
        $repository = new InMemoryMetricRepository();
        $namespacePath = SymbolPath::forNamespace('App');
        $repository->add($namespacePath, (new MetricBag())->with('design.dit.max', 1), null, null);

        $repository->addSubjectScalar(MetricSubject::aggregate($namespacePath), 'design.dit.max', 4);

        self::assertSame(4, $repository->get($namespacePath)->get('design.dit.max'));
    }

    #[Test]
    public function itOverlaysOneLogicalGraphValueOnEachExactDuplicateWithoutCollapsingDeclarationValues(): void
    {
        $logical = SymbolPath::forClass('App', 'Twin');
        $first = self::classSubject($logical, RelativePath::fromString('src/First.php'));
        $second = self::classSubject($logical, RelativePath::fromString('src/Second.php'));
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('size.class-loc', SymbolLevel::Class_),
            new MetricDefinition('coupling.cbo', SymbolLevel::Class_, classKeyScope: ClassKeyScope::LogicalName),
        ]);
        $repository->addSubject($first, MetricBag::fromArray(['size.class-loc' => 10]), RelativePath::fromString('src/First.php'), 1);
        $repository->addSubject($second, MetricBag::fromArray(['size.class-loc' => 20]), RelativePath::fromString('src/Second.php'), 1);
        $repository->add($logical, MetricBag::fromArray(['coupling.cbo' => 3]), null, null);

        self::assertCount(2, iterator_to_array($repository->allClassDeclarations(), false));
        self::assertCount(1, iterator_to_array($repository->allLogicalClasses(), false));
        self::assertCount(3, $repository->forNamespace('App'));
        self::assertSame(10, $repository->getSubject($first)->get('size.class-loc'));
        self::assertSame(20, $repository->getSubject($second)->get('size.class-loc'));
        self::assertSame(3, $repository->getSubject($first)->get('coupling.cbo'));
        self::assertSame(3, $repository->getSubject($second)->get('coupling.cbo'));
        self::assertNull($repository->getSubject(MetricSubject::logicalClass(new LogicalClassPath($logical)))->get('size.class-loc'));
    }

    #[Test]
    public function itRefusesEveryUntypedClassReaderEvenWithOneDeclaration(): void
    {
        $logical = SymbolPath::forClass('App', 'Only');
        $repository = new InMemoryMetricRepository();
        $repository->addSubject(self::classSubject($logical, RelativePath::fromString('src/Only.php')), new MetricBag(), RelativePath::fromString('src/Only.php'), 1);

        foreach ([
            static fn() => $repository->get($logical),
            static fn() => $repository->has($logical),
            static fn() => iterator_to_array($repository->all(SymbolLevel::Class_), false),
        ] as $read) {
            try {
                $read();
                self::fail('An untyped class read must refuse even one declaration');
            } catch (LogicException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesAnUndeclaredClassKeyAndAConflictingMergeRegistry(): void
    {
        $logical = SymbolPath::forClass('App', 'Only');
        $subject = self::classSubject($logical, RelativePath::fromString('src/Only.php'));
        $unregistered = new InMemoryMetricRepository();
        try {
            $unregistered->addSubject($subject, MetricBag::fromArray(['size.class-loc' => 1]), null, null);
            self::fail('Undeclared class metrics must refuse');
        } catch (LogicException $e) {
            self::assertStringContainsString('not declared', $e->getMessage());
        }

        $declaration = $this->declarationRepository(['fixture.scope']);
        $logicalRepository = $this->logicalRepository(['fixture.scope']);
        self::expectException(LogicException::class);
        self::expectExceptionMessage('Conflicting class scope');
        $declaration->mergedWith($logicalRepository);
    }

    #[Test]
    public function itKeepsClassScopeRegistriesIndependentAcrossSuccessfulAndConflictingMerges(): void
    {
        $logical = SymbolPath::forClass('App', 'Scoped');
        $subject = self::classSubject($logical, RelativePath::fromString('src/Scoped.php'));
        $left = $this->declarationRepository(['fixture.left']);
        $right = $this->declarationRepository(['fixture.right']);

        foreach ([$left->mergedWith($right), $right->mergedWith($left)] as $merged) {
            self::assertNotNull($merged);
            $merged->addSubject($subject, MetricBag::fromArray(['fixture.left' => 1, 'fixture.right' => 2]), null, null);
            self::assertSame(1, $merged->getSubject($subject)->get('fixture.left'));
            self::assertSame(2, $merged->getSubject($subject)->get('fixture.right'));
        }

        foreach ([[$left, 'fixture.left', 'fixture.right'], [$right, 'fixture.right', 'fixture.left']] as [$original, $own, $foreign]) {
            $original->addSubject($subject, MetricBag::fromArray([$own => 1]), null, null);
            try {
                $original->addSubject($subject, MetricBag::fromArray([$foreign => 2]), null, null);
                self::fail('Successful merge must not add foreign scopes to an original repository');
            } catch (LogicException $e) {
                self::assertStringContainsString('not declared', $e->getMessage());
            }
        }

        $declaration = $this->declarationRepository(['fixture.conflict']);
        $logicalRepository = $this->logicalRepository(['fixture.conflict']);
        foreach ([[$declaration, $logicalRepository], [$logicalRepository, $declaration]] as [$first, $second]) {
            try {
                $first->mergedWith($second);
                self::fail('Conflicting class scopes must refuse in either merge order');
            } catch (LogicException $e) {
                self::assertStringContainsString('Conflicting class scope', $e->getMessage());
            }
        }

        $declaration->addSubject($subject, MetricBag::fromArray(['fixture.conflict' => 1]), null, null);
        $logicalRepository->addSubject(MetricSubject::logicalClass(new LogicalClassPath($logical)), MetricBag::fromArray(['fixture.conflict' => 2]), null, null);
        foreach ([
            static fn() => $declaration->addSubject(MetricSubject::logicalClass(new LogicalClassPath($logical)), MetricBag::fromArray(['fixture.conflict' => 3]), null, null),
            static fn() => $logicalRepository->addSubject($subject, MetricBag::fromArray(['fixture.conflict' => 3]), null, null),
        ] as $foreignWrite) {
            try {
                $foreignWrite();
                self::fail('Failed merge must not change either original class scope');
            } catch (LogicException $e) {
                self::assertStringContainsString('not declared', $e->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesConflictingAnonymousContextWhenMergingCallableRecords(): void
    {
        $file = RelativePath::fromString('src/Anonymous.php');
        $method = DeclarationPath::of(SymbolPath::forMethod('App', 'Anonymous', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $first = new InMemoryMetricRepository();
        $second = new InMemoryMetricRepository();
        $first->addCallable(new CallableWithMetrics(
            $method,
            10,
            CallableKind::Method,
            null,
            null,
            null,
            new MetricBag(),
            2,
            true,
        ));
        $second->addCallable(new CallableWithMetrics(
            $method,
            10,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of(SymbolPath::forClass($method->logical->namespace ?? '', $method->logical->type ?? ''), $method->file, DeclarationOrdinal::fromRank(0)),
            new MetricBag(),
            2,
            false,
        ));

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Conflicting callable metadata');
        $first->mergedWith($second);
    }

    private function assertLocationFreeLogicalClassProjection(InMemoryMetricRepository $repository): void
    {
        $logicalClasses = iterator_to_array($repository->allLogicalClasses(), false);
        self::assertCount(1, $logicalClasses);
        self::assertNull($logicalClasses[0]->file);
        self::assertNull($logicalClasses[0]->line);
    }

    private static function classSubject(SymbolPath $logical, RelativePath $file, int $ordinal = 0): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank($ordinal)));
    }

    /** @param list<string> $keys */
    private function declarationRepository(array $keys): InMemoryMetricRepository
    {
        return new InMemoryMetricRepository(array_map(
            static fn(string $key): MetricDefinition => new MetricDefinition($key, SymbolLevel::Class_, classKeyScope: ClassKeyScope::Declaration),
            $keys,
        ));
    }

    /** @param list<string> $keys */
    private function logicalRepository(array $keys): InMemoryMetricRepository
    {
        return new InMemoryMetricRepository(array_map(
            static fn(string $key): MetricDefinition => new MetricDefinition($key, SymbolLevel::Class_, classKeyScope: ClassKeyScope::LogicalName),
            $keys,
        ));
    }

    private function addCallable(
        InMemoryMetricRepository $repository,
        SymbolPath $symbol,
        MetricBag $metrics,
        RelativePath $file,
        int $startFilePos,
    ): void {
        $ownerDeclaration = DeclarationPath::of(
            SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? ''),
            $file,
            DeclarationOrdinal::fromRank(0),
        );
        $repository->addSubject(MetricSubject::declaration($ownerDeclaration), new MetricBag(), $file, 1);
        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(1)),
            $startFilePos,
            CallableKind::Method,
            null,
            null,
            $ownerDeclaration,
            $metrics,
        ));
    }
}
