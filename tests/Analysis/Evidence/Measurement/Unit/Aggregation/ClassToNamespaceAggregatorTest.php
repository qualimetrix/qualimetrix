<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\ClassToNamespaceAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceMetricContributions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Size\ClassCountCollector;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ClassToNamespaceAggregator::class)]
#[CoversClass(AggregationHelper::class)]
#[CoversClass(NamespaceMetricContributions::class)]
final class ClassToNamespaceAggregatorTest extends TestCase
{
    #[Test]
    public function itFoldsTwoDeclarationsButNotTheirLogicalGraphProjection(): void
    {
        $definitions = [
            new MetricDefinition('size.method-count', SymbolLevel::Class_, [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average]]),
            new MetricDefinition('coupling.cbo', SymbolLevel::Class_, [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average]], classKeyScope: ClassKeyScope::LogicalName),
        ];
        $repository = new InMemoryMetricRepository($definitions);
        $file = RelativePath::fromString('src/Duplicate.php');
        $logical = SymbolPath::forClass('App', 'Duplicate');

        foreach ([1, 2] as $ordinal => $count) {
            $repository->addSubject(
                MetricSubject::declaration(DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank($ordinal))),
                MetricBag::fromArray(['size.method-count' => $count]),
                $file,
                $ordinal + 2,
            );
        }
        $repository->addSubject(MetricSubject::logicalClass(new LogicalClassPath($logical)), MetricBag::fromArray(['coupling.cbo' => 3]), $file, 2);

        (new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class)))->aggregate($repository, $definitions);

        $namespace = $repository->get(SymbolPath::forNamespace('App'));
        self::assertSame(3, $namespace->get('size.method-count.sum'));
        self::assertSame(2, $namespace->get('size.method-count.count'));
        self::assertSame(6, $namespace->get('coupling.cbo.sum'));
        self::assertSame(2, $namespace->get('coupling.cbo.count'));
    }

    #[Test]
    public function itUsesExplicitNamespaceContributionsInsteadOfCopyingTheWholeFileBag(): void
    {
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('size.loc', SymbolLevel::File, [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average]], namespaceFileContribution: true),
            new MetricDefinition('size.class-count', SymbolLevel::File, [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum]], namespaceFileContribution: true),
        ]);
        $file = RelativePath::fromString('src/Multi.php');
        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray([
            'size.loc' => 20,
            'size.class-count' => 2,
        ]), $file, 1);
        $repository->add(SymbolPath::forNamespace('One'), MetricBag::fromArray([
            'size.loc' => 8,
            'size.class-count' => 1,
        ])->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.loc'])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.class-count']), $file, 2);
        $repository->add(SymbolPath::forNamespace('Two'), MetricBag::fromArray([
            'size.loc' => 9,
            'size.class-count' => 1,
        ])->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.loc'])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.class-count']), $file, 10);

        $definitions = [
            new MetricDefinition('size.loc', SymbolLevel::File, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            ], namespaceFileContribution: true),
            new MetricDefinition('size.class-count', SymbolLevel::File, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            ], namespaceFileContribution: true),
        ];

        (new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class)))->aggregate($repository, $definitions);

        self::assertSame(8, $repository->get(SymbolPath::forNamespace('One'))->get('size.loc.sum'));
        self::assertSame(9, $repository->get(SymbolPath::forNamespace('Two'))->get('size.loc.sum'));
        self::assertSame(1, $repository->get(SymbolPath::forNamespace('One'))->get('size.class-count.sum'));
        self::assertSame(1, $repository->get(SymbolPath::forNamespace('Two'))->get('size.class-count.sum'));

        foreach (['One' => 8, 'Two' => 9] as $namespace => $total) {
            $metrics = $repository->get(SymbolPath::forNamespace($namespace));
            self::assertSame(1, $metrics->get('size.loc.count'));
            self::assertEquals($total, $metrics->get('size.loc.avg'));
        }
    }

    #[Test]
    public function itPreservesNamespaceAverageAcrossMultiplePhysicalFileContributions(): void
    {
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('size.loc', SymbolLevel::File, [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average]], namespaceFileContribution: true),
        ]);
        $file = RelativePath::fromString('src/A.php');
        $repository->add(SymbolPath::forNamespace('App'), MetricBag::fromArray([
            'size.loc' => 30,
        ])->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.loc'])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.loc']), $file, 2);
        $definitions = [new MetricDefinition('size.loc', SymbolLevel::File, [
            SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
        ], namespaceFileContribution: true)];

        (new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class)))->aggregate($repository, $definitions);

        $namespace = $repository->get(SymbolPath::forNamespace('App'));
        self::assertSame(30, $namespace->get('size.loc.sum'));
        self::assertSame(15, $namespace->get('size.loc.avg'));
        self::assertSame(2, $namespace->get('size.loc.count'));
    }

    #[Test]
    public function itAggregatesCaseVariantsIntoOneNamespace(): void
    {
        $repository = new InMemoryMetricRepository([
            new MetricDefinition('size.class-count', SymbolLevel::File, [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum]], namespaceFileContribution: true),
        ]);
        foreach ([['App\\Web', 'First', 'src/First.php'], ['App\\web', 'Second', 'src/Second.php']] as [$namespace, $class, $path]) {
            $file = RelativePath::fromString($path);
            $repository->add(SymbolPath::forClass($namespace, $class), new MetricBag(), $file, 2);
            $repository->add(
                SymbolPath::forFile($file),
                MetricBag::fromArray(['size.class-count' => 1]),
                $file,
                1,
            );
        }
        $repository->add(
            SymbolPath::forNamespace('App\\web'),
            MetricBag::fromArray(['size.class-count' => 2])
                ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.class-count'])
                ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.class-count']),
            RelativePath::fromString('src/Second.php'),
            2,
        );

        (new ClassToNamespaceAggregator(self::createStub(ProfilerInterface::class)))->aggregate($repository, [
            new MetricDefinition('size.class-count', SymbolLevel::File, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            ], namespaceFileContribution: true),
        ]);

        self::assertSame(['App\\Web'], $repository->getNamespaces());
        self::assertSame(2, $repository->get(SymbolPath::forNamespace('APP\\WEB'))->get('size.class-count.sum'));
    }

    #[Test]
    public function itKeepsExplicitCountTotalsExactAcrossTheNamespaceTree(): void
    {
        $repository = $this->methodRepository();
        $namespace = 'App\\Domain\\Leaf';
        $file = RelativePath::fromString('src/Domain/Leaf.php');

        $repository->add(
            SymbolPath::forFile($file),
            MetricBag::fromArray([
                'size.class-count' => 6,
                'size.abstract-class-count' => 1,
                'size.interface-count' => 1,
                'size.trait-count' => 1,
                'size.enum-count' => 1,
            ]),
            $file,
            1,
        );

        for ($i = 1; $i <= 6; ++$i) {
            $repository->add(
                SymbolPath::forClass($namespace, "Class{$i}"),
                new MetricBag(),
                $file,
                $i + 1,
            );
        }

        $repository->add(
            SymbolPath::forNamespace($namespace),
            MetricBag::fromArray([
                'size.class-count' => 6,
                'size.abstract-class-count' => 1,
                'size.interface-count' => 1,
                'size.trait-count' => 1,
                'size.enum-count' => 1,
            ])
                ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.class-count'])
                ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.abstract-class-count'])
                ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.interface-count'])
                ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.trait-count'])
                ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.enum-count']),
            $file,
            1,
        );

        (new MetricAggregator(AggregationHelper::collectDefinitions([
            new ClassCountCollector(),
        ]), self::createStub(ProfilerInterface::class)))->aggregate($repository);

        foreach ([SymbolPath::forNamespace($namespace), SymbolPath::forNamespace('App\\Domain')] as $path) {
            $metrics = $repository->get($path);

            self::assertSame(6, $metrics->get('size.class-count.sum'));
            self::assertSame(1, $metrics->get('size.abstract-class-count.sum'));
            self::assertSame(1, $metrics->get('size.interface-count.sum'));
            self::assertSame(1, $metrics->get('size.trait-count.sum'));
            self::assertSame(1, $metrics->get('size.enum-count.sum'));
        }
    }

    #[Test]
    public function itAggregatesProceduralFileLocToNamespace(): void
    {
        $repository = $this->methodRepository();

        // Add a global function in namespace App\Utils (no class in the file)
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $functionMetrics = (new MetricBag())->with('complexity.ccn', 2);
        $this->addCallable($repository, $functionPath, $functionMetrics, RelativePath::fromString('src/Utils/helpers.php'), 100);

        // Add file-level LOC metrics for the same file
        $fileMetrics = (new MetricBag())
            ->with('size.loc', 50)
            ->with('size.lloc', 40)
            ->with('size.cloc', 5);
        $repository->add(
            SymbolPath::forFile(RelativePath::fromString('src/Utils/helpers.php')),
            $fileMetrics,
            RelativePath::fromString('src/Utils/helpers.php'),
            1,
        );

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([
            new LocCollector(),
        ]), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        // Namespace-level LOC should include the procedural file's LOC
        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Utils'));

        self::assertSame(50, (int) $nsMetrics->get('size.loc.sum'));
        self::assertSame(40, (int) $nsMetrics->get('size.lloc.sum'));
        self::assertSame(5, (int) $nsMetrics->get('size.cloc.sum'));
    }

    #[Test]
    public function itAggregatesMixedClassAndFunctionFileLocToNamespace(): void
    {
        $repository = $this->methodRepository();

        // File with a class
        $repository->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            new MetricBag(),
            RelativePath::fromString('src/Service/UserService.php'),
            5,
        );
        $repository->add(
            SymbolPath::forFile(RelativePath::fromString('src/Service/UserService.php')),
            (new MetricBag())->with('size.loc', 100),
            RelativePath::fromString('src/Service/UserService.php'),
            1,
        );

        // File with only functions (no class)
        $this->addCallable(
            $repository,
            SymbolPath::forGlobalFunction('App\\Service', 'serviceHelper'),
            new MetricBag(),
            RelativePath::fromString('src/Service/helpers.php'),
            30,
        );
        $repository->add(
            SymbolPath::forFile(RelativePath::fromString('src/Service/helpers.php')),
            (new MetricBag())->with('size.loc', 30),
            RelativePath::fromString('src/Service/helpers.php'),
            1,
        );

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([
            new LocCollector(),
        ]), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        // Namespace LOC should include both files
        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        self::assertSame(130, (int) $nsMetrics->get('size.loc.sum')); // 100 + 30
    }

    #[Test]
    public function itAggregatesNonAdditiveMethodMetricsFromRawMethodValues(): void
    {
        $repository = $this->methodRepository();

        // Class with 10 methods, MI avg=80
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addClass($repository, $class1, (new MetricBag())
            ->with('maintainability.mi.avg', 80.0)
            ->with('maintainability.mi.count', 10)
            ->with('maintainability.mi.min', 70.0), 'src/Service/UserService.php', 10);

        // Add 10 method symbols with mi=80 each
        for ($i = 1; $i <= 10; $i++) {
            $this->addMethod($repository, 'App\\Service', 'UserService', "method{$i}", 'src/Service/UserService.php', 'maintainability.mi', 80.0);
        }

        // Class with 2 methods, MI avg=60
        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $this->addClass($repository, $class2, (new MetricBag())
            ->with('maintainability.mi.avg', 60.0)
            ->with('maintainability.mi.count', 2)
            ->with('maintainability.mi.min', 50.0), 'src/Service/OrderService.php', 10);

        // Add 2 method symbols with mi=60 each
        $this->addMethod($repository, 'App\\Service', 'OrderService', 'method1', 'src/Service/OrderService.php', 'maintainability.mi', 60.0);
        $this->addMethod($repository, 'App\\Service', 'OrderService', 'method2', 'src/Service/OrderService.php', 'maintainability.mi', 60.0);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions(), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Average from raw method values: (80*10 + 60*2) / 12 = 920/12 ≈ 76.67
        self::assertEqualsWithDelta(76.67, $nsMetrics->get('maintainability.mi.avg'), 0.01);
        // mi.count = total method count = 12
        self::assertSame(12, $nsMetrics->get('maintainability.mi.count'));
        // mi.min = min of all method values = 60.0
        self::assertEqualsWithDelta(60.0, $nsMetrics->get('maintainability.mi.min'), 0.01);
    }

    #[Test]
    public function itAggregatesMethodMetricsEvenWhenClassLevelCountMissing(): void
    {
        $repository = $this->methodRepository();

        // Class-level data without .count — but method symbols exist
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addClass($repository, $class1, (new MetricBag())
            ->with('maintainability.mi.avg', 80.0)
            ->with('maintainability.mi.min', 70.0), 'src/Service/UserService.php', 10);

        // 1 method with mi=80
        $this->addMethod($repository, 'App\\Service', 'UserService', 'handle', 'src/Service/UserService.php', 'maintainability.mi', 80.0);

        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $this->addClass($repository, $class2, (new MetricBag())
            ->with('maintainability.mi.avg', 60.0)
            ->with('maintainability.mi.min', 50.0), 'src/Service/OrderService.php', 10);

        // 1 method with mi=60
        $this->addMethod($repository, 'App\\Service', 'OrderService', 'process', 'src/Service/OrderService.php', 'maintainability.mi', 60.0);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions(), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Average from raw method values: (80+60)/2 = 70
        self::assertEqualsWithDelta(70.0, $nsMetrics->get('maintainability.mi.avg'), 0.01);
        // mi.count = 2 (from number of method symbols)
        self::assertSame(2, $nsMetrics->get('maintainability.mi.count'));
    }

    #[Test]
    public function itHandlesSingleClassNamespaceCorrectly(): void
    {
        $repository = $this->methodRepository();

        // Single class with 5 methods, all mi=85
        $class1 = SymbolPath::forClass('App\\Single', 'OnlyService');
        $this->addClass($repository, $class1, (new MetricBag())
            ->with('maintainability.mi.avg', 85.0)
            ->with('maintainability.mi.count', 5)
            ->with('maintainability.mi.min', 75.0), 'src/Single/OnlyService.php', 10);

        // Add 5 method symbols with mi=85 each
        for ($i = 1; $i <= 5; $i++) {
            $this->addMethod($repository, 'App\\Single', 'OnlyService', "method{$i}", 'src/Single/OnlyService.php', 'maintainability.mi', 85.0);
        }

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions(), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Single'));

        // All methods have mi=85, so avg = 85.0
        self::assertEqualsWithDelta(85.0, $nsMetrics->get('maintainability.mi.avg'), 0.01);
        self::assertSame(5, $nsMetrics->get('maintainability.mi.count'));
        // min of all method values = 85.0
        self::assertEqualsWithDelta(85.0, $nsMetrics->get('maintainability.mi.min'), 0.01);
    }

    #[Test]
    public function itAggregatesAdditiveMethodMetricsFromRawMethodValues(): void
    {
        $repository = $this->methodRepository();

        // UserService: 4 methods with ccn [5,5,5,5] → sum=20
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addClass($repository, $class1, (new MetricBag())
            ->with('complexity.ccn.sum', 20.0)
            ->with('complexity.ccn.avg', 5.0), 'src/Service/UserService.php', 10);

        for ($i = 1; $i <= 4; $i++) {
            $this->addMethod($repository, 'App\\Service', 'UserService', "method{$i}", 'src/Service/UserService.php', 'complexity.ccn', 5.0);
        }

        // OrderService: 3 methods with ccn [10,10,10] → sum=30
        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $this->addClass($repository, $class2, (new MetricBag())
            ->with('complexity.ccn.sum', 30.0)
            ->with('complexity.ccn.avg', 10.0), 'src/Service/OrderService.php', 10);

        for ($i = 1; $i <= 3; $i++) {
            $this->addMethod($repository, 'App\\Service', 'OrderService', "method{$i}", 'src/Service/OrderService.php', 'complexity.ccn', 10.0);
        }

        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            ],
        );
        $aggregator = new MetricAggregator([$definition], self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // ccn.sum = sum of all 7 method values = 5*4 + 10*3 = 50
        self::assertEqualsWithDelta(50.0, $nsMetrics->get('complexity.ccn.sum'), 0.01);
        // ccn.avg = average of all 7 method values = 50/7 ≈ 7.14
        self::assertEqualsWithDelta(50.0 / 7, $nsMetrics->get('complexity.ccn.avg'), 0.01);
    }

    #[Test]
    public function itAdditiveMetricsAggregateFromMethodValuesRegardlessOfClassWeights(): void
    {
        $repository = $this->methodRepository();

        // UserService: 4 methods with ccn [5,5,5,5] → sum=20
        $class1 = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addClass($repository, $class1, (new MetricBag())
            ->with('complexity.ccn.sum', 20.0)
            ->with('complexity.ccn.avg', 5.0)
            ->with('complexity.ccn.count', 4), 'src/Service/UserService.php', 10);

        for ($i = 1; $i <= 4; $i++) {
            $this->addMethod($repository, 'App\\Service', 'UserService', "method{$i}", 'src/Service/UserService.php', 'complexity.ccn', 5.0);
        }

        // OrderService: 3 methods with ccn [10,10,10] → sum=30
        $class2 = SymbolPath::forClass('App\\Service', 'OrderService');
        $this->addClass($repository, $class2, (new MetricBag())
            ->with('complexity.ccn.sum', 30.0)
            ->with('complexity.ccn.avg', 10.0)
            ->with('complexity.ccn.count', 3), 'src/Service/OrderService.php', 10);

        for ($i = 1; $i <= 3; $i++) {
            $this->addMethod($repository, 'App\\Service', 'OrderService', "method{$i}", 'src/Service/OrderService.php', 'complexity.ccn', 10.0);
        }

        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            ],
        );
        $aggregator = new MetricAggregator([$definition], self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // ccn.sum = sum of all 7 method values = 50
        self::assertEqualsWithDelta(50.0, $nsMetrics->get('complexity.ccn.sum'), 0.01);
        // ccn.avg = average of all 7 method values = 50/7 ≈ 7.14
        self::assertEqualsWithDelta(50.0 / 7, $nsMetrics->get('complexity.ccn.avg'), 0.01);
    }

    #[Test]
    public function itAutoStoresCountAlongsideAnAverageAggregation(): void
    {
        $definition = new MetricDefinition(
            name: 'maintainability.mi',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Average, AggregationStrategy::Min],
            ],
        );

        $metricValues = ['maintainability.mi' => [80.0, 60.0, 70.0]];

        $bag = AggregationHelper::applyAggregations($metricValues, [$definition], SymbolLevel::Namespace_);

        // avg stored
        self::assertEqualsWithDelta(70.0, $bag->get('maintainability.mi.avg'), 0.01);
        // count auto-stored = 3
        self::assertEqualsWithDelta(3.0, $bag->get('maintainability.mi.count'), 0.01);
        // min stored
        self::assertEqualsWithDelta(60.0, $bag->get('maintainability.mi.min'), 0.01);
    }

    #[Test]
    public function itDoesNotDuplicateAnExplicitlyRequestedCount(): void
    {
        $definition = new MetricDefinition(
            name: 'test',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Namespace_->value => [
                    AggregationStrategy::Average,
                    AggregationStrategy::Count,
                ],
            ],
        );

        $metricValues = ['test' => [10.0, 20.0]];

        $bag = AggregationHelper::applyAggregations($metricValues, [$definition], SymbolLevel::Namespace_);

        // Explicit Count strategy: count = 2
        self::assertEqualsWithDelta(2.0, $bag->get('test.count'), 0.01);
        self::assertEqualsWithDelta(15.0, $bag->get('test.avg'), 0.01);
    }

    #[Test]
    public function itUsesRawMethodValuesNotClassBagForNamespaceAggregation(): void
    {
        $repository = $this->methodRepository();

        // Class bag has ccn.sum=999 (stale/incorrect value)
        // but raw method values are [2, 3] → correct sum=5
        $class = SymbolPath::forClass('App\\Service', 'Svc');
        $this->addClass($repository, $class, (new MetricBag())
            ->with('complexity.ccn.sum', 999)
            ->with('complexity.ccn.avg', 499.5)
            ->with('complexity.ccn.max', 999)
            ->with('complexity.ccn.count', 2), 'src/Service/Svc.php', 10);

        $this->addMethod($repository, 'App\\Service', 'Svc', 'doA', 'src/Service/Svc.php', 'complexity.ccn', 2);
        $this->addMethod($repository, 'App\\Service', 'Svc', 'doB', 'src/Service/Svc.php', 'complexity.ccn', 3);

        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            ],
        );
        $aggregator = new MetricAggregator([$definition], self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        // Namespace must use raw method values [2, 3], NOT class bag ccn.sum=999
        self::assertEqualsWithDelta(5.0, $nsMetrics->get('complexity.ccn.sum'), 0.01, 'sum from raw methods');
        self::assertEqualsWithDelta(2.5, $nsMetrics->get('complexity.ccn.avg'), 0.01, 'avg from raw methods');
        self::assertSame(3, (int) $nsMetrics->get('complexity.ccn.max'), 'max from raw methods');
    }

    private function addMethod(
        InMemoryMetricRepository $repository,
        string $namespace,
        string $class,
        string $method,
        string $file,
        string $metric,
        int|float $value,
    ): void {
        $this->addCallable(
            $repository,
            SymbolPath::forMethod($namespace, $class, $method),
            (new MetricBag())->with($metric, $value),
            RelativePath::fromString($file),
            10,
        );
    }

    private function methodRepository(): InMemoryMetricRepository
    {
        return new InMemoryMetricRepository([
            new MetricDefinition('maintainability.mi', SymbolLevel::Callable, [
                SymbolLevel::Class_->value => [AggregationStrategy::Average, AggregationStrategy::Min],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Average, AggregationStrategy::Min],
            ]),
            new MetricDefinition('complexity.ccn', SymbolLevel::Callable, [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            ]),
            new MetricDefinition('size.symbol-method-count', SymbolLevel::Class_),
        ]);
    }

    private function addClass(InMemoryMetricRepository $repository, SymbolPath $class, MetricBag $metrics, string $file, int $line): void
    {
        $path = RelativePath::fromString($file);
        $declaration = DeclarationPath::of($class, $path, DeclarationOrdinal::fromRank(0));
        $repository->addSubject(MetricSubject::declaration($declaration), $metrics, $path, $line);
    }

    private function addCallable(InMemoryMetricRepository $repository, SymbolPath $symbol, MetricBag $metrics, RelativePath $file, int $startFilePos): void
    {
        $owner = $symbol->getType() === \Qualimetrix\Core\Symbol\SymbolType::Method
            ? new LogicalClassPath(SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? ''))
            : null;
        $ownerDeclaration = $owner === null
            ? null
            : DeclarationPath::of($owner->symbolPath, $file, DeclarationOrdinal::fromRank(0));
        if ($ownerDeclaration !== null) {
            $repository->addSubject(MetricSubject::declaration($ownerDeclaration), new MetricBag(), $file, 1);
        }
        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0)),
            $startFilePos,
            $symbol->getType() === \Qualimetrix\Core\Symbol\SymbolType::Method ? CallableKind::Method : CallableKind::Function,
            null,
            null,
            $owner,
            $metrics,
            classAggregationOwnerDeclaration: $ownerDeclaration,
        ));
    }
}
