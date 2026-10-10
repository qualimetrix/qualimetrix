<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\ClassRankResolver;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\ImpactCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit\MetricRepositoryTestHelper;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(SummaryEnricher::class)]
final class SummaryEnricherTest extends TestCase
{
    use MetricRepositoryTestHelper;
    private SummaryEnricher $enricher;

    protected function setUp(): void
    {
        $this->configureEnricher($this->defaultDefinitionCatalog());
    }

    private function configureEnricher(ComputedMetricDefinitionCatalogInterface $catalog): void
    {
        $registry = new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues());
        $this->enricher = new SummaryEnricher(
            new DebtCalculator($registry),
            new ImpactCalculator(new ClassRankResolver(), $registry),
            new HealthSummaryBuilder(
                new HealthMetricCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())),
                $catalog,
            ),
        );
    }

    #[Test]
    public function itPreservesPopulationAndComputedAbsencesOnBothEnrichmentBranches(): void
    {
        $trace = new \Qualimetrix\Analysis\Finding\Population\PopulationTrace();
        $trace->record(
            'complexity.ccn',
            new \Qualimetrix\Analysis\Finding\Contract\FindingChannel('complexity.ccn'),
            \Qualimetrix\Core\Symbol\SymbolLevel::Callable,
            \Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity::occurrence('missing', 0, 'callable'),
            'callable-value',
            'Callable complexity was not published.',
        );
        $population = $trace->freeze();
        $summary = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricValueAbsence(
                'computed.custom',
                \Qualimetrix\Core\Symbol\SymbolLevel::Project,
                noValueCount: 1,
            ),
        ]);
        foreach ([null, $this->createMetricRepository(projectMetrics: MetricBag::fromArray(['size.loc.sum' => 10]))] as $metrics) {
            $report = new Report(
                \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
                [],
                1,
                0,
                0.1,
                0,
                0,
                metrics: $metrics,
                computedMetricEvaluation: $summary,
                population: $population,
            );
            $result = $this->enricher->enrich($report);
            self::assertSame($population, $result->population);
            self::assertSame($summary, $result->computedMetricEvaluation);
            self::assertSame(1, $result->population->abstentions()[0]->count);
            self::assertSame([], $result->findings);
            if ($metrics === null) {
                self::assertSame($report, $result);
            } else {
                self::assertNotSame($report, $result);
            }
        }
    }

    #[Test]
    public function itReturnsUnchangedReportWhenNoMetrics(): void
    {
        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );

        $result = $this->enricher->enrich($report);

        self::assertSame($report, $result);
        self::assertSame([], $result->healthScores);
        self::assertSame([], $result->worstNamespaces);
        self::assertSame([], $result->worstClasses);
        self::assertSame(0, $result->techDebtMinutes);
    }

    #[Test]
    public function itEnrichesWithTechDebt(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
        );

        $finding = new Finding(
            location: new Location(RelativePath::fromString('test.php'), 1),
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('test.php'))),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('test.php')),
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [$finding, $finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        // complexity.ccn = 30 min per finding, 2 findings = 60
        self::assertSame(60, $result->techDebtMinutes);
    }

    #[Test]
    public function itWorstNamespaces(): void
    {
        $nsSymbol = SymbolPath::forNamespace('App\\Payment');
        $nsMetrics = MetricBag::fromArray([
            'health.overall' => 31.0,
            'health.complexity' => 28.0,
            'health.cohesion' => 25.0,
            'health.coupling' => 52.0,
            'health.typing' => 35.0,
            'health.maintainability' => 22.0,
            // Own count beside the subtree sum: the worst-offender guard asks
            // whether this namespace declares classes itself.
            'size.class-count' => 4,
            'size.class-count.sum' => 4,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
                'size.symbol-method-count' => 0,
                'size.symbol-class-count' => 4,
                'size.symbol-declaring-namespace-count' => 1,
            ]),
            namespaces: [
                new SymbolInfo($nsSymbol, RelativePath::fromString('src/Payment'), null),
            ],
            namespaceMetrics: [
                'ns:App\\Payment' => $nsMetrics,
            ],
            classes: array_map(static fn(string $name): SymbolInfo => new SymbolInfo(self::exactClassSubject(SymbolPath::forClass('App\\Payment', $name), 'src/Payment/' . $name . '.php'), RelativePath::fromString('src/Payment/' . $name . '.php'), 1), ['PaymentService', 'Second', 'Third', 'Fourth']),
        );

        $this->configureEnricher(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions(array_values(\Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults::getDefaults())));

        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Payment/PaymentService.php'), 42),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Payment', 'PaymentService'), RelativePath::fromString('src/Payment/PaymentService.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App\\Payment', 'PaymentService'),
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [$finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->worstNamespaces);
        $ns = $result->worstNamespaces[0];
        self::assertSame(31.0, $ns->healthOverall);
        self::assertSame(4, $ns->classCount);
        self::assertSame(1, $ns->violationCount);
        // typing (35 vs warn 80, delta=-45) and maintainability (22 vs warn 65, delta=-43) are worst
        self::assertStringContainsString('low type safety', $ns->reason);
        self::assertNull($ns->file);
        self::assertArrayHasKey('complexity', $ns->healthScores);
    }

    #[Test]
    public function itWorstClasses(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'PaymentService');
        $classSymbolSubject = self::exactClassSubject($classSymbol, 'src/Service/PaymentService.php');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 28.0,
            'health.complexity' => 22.0,
            'health.cohesion' => 8.0,
            'health.coupling' => 35.0,
            'health.typing' => 20.0,
            'health.maintainability' => 15.0,
            'size.method-count' => 32,
            'coupling.cbo' => 18,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
            classes: [
                new SymbolInfo($classSymbolSubject, RelativePath::fromString('src/Service/PaymentService.php'), 10),
            ],
            classMetrics: [
                $classSymbolSubject->toCanonical() => $classMetrics,
            ],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->worstClasses);
        $cls = $result->worstClasses[0];
        self::assertSame(28.0, $cls->healthOverall);
        self::assertSame('src/Service/PaymentService.php', $cls->file?->value());
        self::assertSame(0, $cls->classCount);
        self::assertArrayHasKey('size.method-count', $cls->metrics);
        self::assertSame(32, $cls->metrics['size.method-count']);
    }

    #[Test]
    public function itSkipsSymbolsAboveWarningThreshold(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'GoodService');
        $classSymbolSubject = self::exactClassSubject($classSymbol, 'src/Service/GoodService.php');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 85.0,
            'health.complexity' => 80.0,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 85.0,
            ]),
            classes: [
                new SymbolInfo($classSymbolSubject, RelativePath::fromString('src/Service/GoodService.php'), 1),
            ],
            classMetrics: [
                $classSymbolSubject->toCanonical() => $classMetrics,
            ],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        // H3: Always show top-N classes regardless of threshold
        self::assertCount(1, $result->worstClasses);
        self::assertSame('App\\Service\\GoodService', $result->worstClasses[0]->symbolPath->toString());
        self::assertSame(85.0, $result->worstClasses[0]->healthOverall);
    }

    #[Test]
    public function itPreservesOriginalReportFields(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
        );

        $finding = new Finding(
            location: new Location(RelativePath::fromString('test.php'), 1),
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('test.php'))),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('test.php')),
            ruleName: 'test',
            code: 'test',
            message: 'test message',
            severity: Severity::Warning,
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [$finding],
            filesAnalyzed: 42,
            filesSkipped: 3,
            duration: 5.5,
            errorCount: 0,
            warningCount: 1,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->findings);
        self::assertSame(42, $result->filesAnalyzed);
        self::assertSame(3, $result->filesSkipped);
        self::assertSame(5.5, $result->duration);
        self::assertSame(0, $result->errorCount);
        self::assertSame(1, $result->warningCount);
        self::assertSame($metrics, $result->metrics);
    }

    #[Test]
    public function itHealthScoresEmptyWhenNoProjectHealthMetrics(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'complexity.ccn.avg' => 5.0,
                'size.loc' => 1000,
            ]),
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $this->configureEnricher(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions([]));
        $result = $this->enricher->enrich($report);

        self::assertSame([], $result->healthScores);
    }

    #[Test]
    public function itDecompositionShownWhenScoreBelowWarning(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.complexity' => 30.0,
                'health.overall' => 50.0,
                'complexity.ccn.avg' => 12.0,
                'complexity.cognitive.avg' => 10.0,
                // The population every run writes from its symbol list, and
                // what the complexity coverage divides by.
                'size.symbol-method-count' => 40,
            ]),
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 50,
            filesSkipped: 0,
            duration: 1.5,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('complexity', $result->healthScores);
        $complexity = $result->healthScores['complexity'];
        self::assertSame(30.0, $complexity->score);
        self::assertCount(5, $complexity->decomposition);
        self::assertNull($complexity->decomposition[2]->value);
        self::assertSame('complexity.ccn.avg', $complexity->decomposition[0]->metricKey);
        self::assertSame(12.0, $complexity->decomposition[0]->value);
        self::assertSame('complexity.cognitive.avg', $complexity->decomposition[1]->metricKey);
        self::assertSame(10.0, $complexity->decomposition[1]->value);
    }

    #[Test]
    public function itNullMetricsReturnsUnchangedReport(): void
    {
        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: null,
        );

        $result = $this->enricher->enrich($report);

        self::assertSame($report, $result);
        self::assertSame([], $result->healthScores);
    }

    #[Test]
    public function itDebtPer1kLocComputedCorrectly(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
                'size.loc.sum' => 5000,
            ]),
        );

        $finding = new Finding(
            location: new Location(RelativePath::fromString('test.php'), 1),
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('test.php'))),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('test.php')),
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [$finding, $finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        // 2 findings * 30 min = 60 min total debt, 5000 LOC = 5 kLOC
        // debtPer1kLoc = 60 / 5 = 12.0
        self::assertSame(12.0, $result->debtPer1kLoc);
    }

    #[Test]
    public function itDebtPer1kLocZeroWhenNoFindings(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 85.0,
                'size.loc.sum' => 10000,
            ]),
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertSame(0.0, $result->debtPer1kLoc);
    }

    #[Test]
    public function itDebtPer1kLocNullWhenNoLoc(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertNull($result->debtPer1kLoc);
    }

    #[Test]
    public function itTypingNAWhenOtherDimensionsExist(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.complexity' => 65.0,
                'health.overall' => 72.0,
                // The population every run writes from its symbol list, and
                // what the complexity coverage divides by.
                'size.symbol-method-count' => 8,
                'size.symbol-class-count' => 0,
                'size.symbol-declaring-namespace-count' => 0,
            ]),
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $this->configureEnricher(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions(array_values(\Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults::getDefaults())));
        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('typing', $result->healthScores);
        $typing = $result->healthScores['typing'];
        self::assertNull($typing->score);
        self::assertSame('Not measured', $typing->label);
    }

    /**
     * The typing dimension is added beside other health dimensions, not on its
     * own: with none of them present there is nothing for it to sit beside.
     * The neighbouring case shows the other half, where it is added as N/A.
     */
    #[Test]
    public function itTypingNotAddedWhenNoDimensions(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'complexity.ccn.avg' => 5.0,
                'size.loc' => 1000,
            ]),
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($metrics),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertArrayNotHasKey('typing', $result->healthScores);
    }

    #[Test]
    public function itPreservesComputedAbsencesThroughTheFullReportCopy(): void
    {
        $summary = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricValueAbsence(
                'computed.custom',
                \Qualimetrix\Core\Symbol\SymbolLevel::Project,
                2,
                1,
                ['missing.input'],
                [\Qualimetrix\Core\Symbol\MetricSubject::aggregate(\Qualimetrix\Core\Symbol\SymbolPath::forProject())],
            ),
        ]);
        $report = new Report(\Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($this->createMetricRepository(new MetricBag())), [], 1, 0, 0.0, 0, 0, metrics: $this->createMetricRepository(new MetricBag()), computedMetricEvaluation: $summary);
        $enriched = $this->enricher->enrich($report);
        self::assertNotSame($report, $enriched);
        self::assertSame($summary, $enriched->computedMetricEvaluation);
    }

    #[Test]
    public function itKeepsTheMeasuredReportSnapshotThroughBothPresentationsAfterCatalogReplacement(): void
    {
        $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
        $catalog = $container->get(ComputedMetricDefinitionCatalogInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis::class, $catalog);
        $definitions = \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults::getDefaults();
        $catalog->replace(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions(array_values($definitions)));
        $catalogReads = 0;
        $trackedCatalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $trackedCatalog->method('all')->willReturnCallback(static function () use ($catalog, &$catalogReads): array {
            ++$catalogReads;
            return $catalog->all();
        });
        $this->configureEnricher($trackedCatalog);
        $repository = new \Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository([
            new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition('health.overall', \Qualimetrix\Core\Symbol\SymbolLevel::Class_),
            new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition('health.complexity', \Qualimetrix\Core\Symbol\SymbolLevel::Class_),
        ]);
        $path = SymbolPath::forClass('App', 'Type');
        $file = RelativePath::fromString('src/Type.php');
        $subject = MetricSubject::declaration(DeclarationPath::of($path, $file, DeclarationOrdinal::fromRank(0)));
        $repository->addSubject($subject, MetricBag::fromArray(['health.overall' => 82.8, 'health.complexity' => 55.0]), $file, 1);
        $repositoryReads = [];
        $trackedRepository = self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class);
        foreach ([
            'get' => $repository->get(...),
            'getNamespaces' => $repository->getNamespaces(...),
            'all' => $repository->all(...),
            'allClassDeclarations' => $repository->allClassDeclarations(...),
            'getSubject' => $repository->getSubject(...),
            'allCallables' => $repository->allCallables(...),
            'allDeclarations' => $repository->allDeclarations(...),
            'allLogicalClasses' => $repository->allLogicalClasses(...),
        ] as $method => $read) {
            $repositoryReads[$method] = 0;
            $trackedRepository->method($method)->willReturnCallback(static function (...$arguments) use ($read, $method, &$repositoryReads) {
                ++$repositoryReads[$method];
                return $read(...$arguments);
            });
        }
        $raw = new Report(\Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository($repository), [], 2, 0, 0.0, 0, 0, metrics: $trackedRepository);
        $report = $this->enricher->enrich($raw);
        $record = $report->worstClasses[0];
        $readsAtPublication = $repositoryReads;
        $catalogReadsAtPublication = $catalogReads;
        foreach (['health.overall' => [90.0, 85.0], 'health.complexity' => [60.0, 40.0]] as $name => [$warning, $error]) {
            $old = $definitions[$name];
            $definitions[$name] = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition($name, $old->formulas, $old->description, $old->levels, $old->inverted, $warning, $error, $old->applicability);
        }
        $catalog->replace(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions(array_values($definitions)));
        $filter = new \Qualimetrix\Reporting\DrillDown\FindingFilter();
        $selector = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown();
        $summary = new \Qualimetrix\Reporting\Formatter\Summary\OffenderListRenderer($filter, $selector);
        $json = new \Qualimetrix\Reporting\Formatter\Json\JsonOffenderSection($selector, $filter, new \Qualimetrix\Reporting\Formatter\Json\JsonSanitizer());
        $context = new \Qualimetrix\Reporting\FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('App'));
        self::assertSame([$record], $summary->resolveWorstClasses($report, $context));
        $lines = [];
        $summary->renderWorstClasses($report, new \Qualimetrix\Reporting\Formatter\Ansi\AnsiColor(true), $context, $lines);
        self::assertStringContainsString("\033[32m82.8\033[0m", implode("\n", $lines));
        $selected = $json->formatClasses($report, $context, 20);
        self::assertSame('', $selected[0]['reason']);
        self::assertSame('Excellent', $selected[0]['label']);
        self::assertSame([$record], $report->worstClasses);
        self::assertSame([50.0, 30.0], $record->overallThresholds);
        self::assertSame($readsAtPublication, $repositoryReads);
        self::assertSame($catalogReadsAtPublication, $catalogReads);
        $next = $this->enricher->enrich($raw);
        self::assertNotSame($record, $next->worstClasses[0]);
        self::assertSame([90.0, 85.0], $next->worstClasses[0]->overallThresholds);
        self::assertSame('high complexity', $next->worstClasses[0]->reason);
        self::assertSame('Critical', $next->worstClasses[0]->label);
    }

}
