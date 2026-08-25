<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

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
        $registry = new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues());
        $this->enricher = new SummaryEnricher(
            new DebtCalculator($registry),
            new ImpactCalculator(new ClassRankResolver(), $registry),
            new HealthSummaryBuilder(
                new HealthMetricCatalog(),
                self::createStub(ComputedMetricDefinitionCatalogInterface::class),
            ),
        );
    }

    #[Test]
    public function itReturnsUnchangedReportWhenNoMetrics(): void
    {
        $report = new Report(
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
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
            findings: [$finding, $finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        // complexity.cyclomatic = 30 min per finding, 2 findings = 60
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
            'classCount.sum' => 4,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
            namespaces: [
                new SymbolInfo($nsSymbol, RelativePath::fromString('src/Payment'), null),
            ],
            namespaceMetrics: [
                'ns:App\\Payment' => $nsMetrics,
            ],
        );

        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Payment/PaymentService.php'), 42),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Payment', 'PaymentService'), RelativePath::fromString('src/Payment/PaymentService.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App\\Payment', 'PaymentService'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
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
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 28.0,
            'health.complexity' => 22.0,
            'health.cohesion' => 8.0,
            'health.coupling' => 35.0,
            'health.typing' => 20.0,
            'health.maintainability' => 15.0,
            'methodCount' => 32,
            'cbo' => 18,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
            classes: [
                new SymbolInfo($classSymbol, RelativePath::fromString('src/Service/PaymentService.php'), 10),
            ],
            classMetrics: [
                'class:App\\Service\\PaymentService' => $classMetrics,
            ],
        );

        $report = new Report(
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
        self::assertArrayHasKey('methodCount', $cls->metrics);
        self::assertSame(32, $cls->metrics['methodCount']);
    }

    #[Test]
    public function itSkipsSymbolsAboveWarningThreshold(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'GoodService');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 85.0,
            'health.complexity' => 80.0,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 85.0,
            ]),
            classes: [
                new SymbolInfo($classSymbol, RelativePath::fromString('src/Service/GoodService.php'), 1),
            ],
            classMetrics: [
                'class:App\\Service\\GoodService' => $classMetrics,
            ],
        );

        $report = new Report(
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
                'ccn.avg' => 5.0,
                'loc' => 1000,
            ]),
        );

        $report = new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

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
                'ccn.avg' => 12.0,
                'cognitive.avg' => 10.0,
            ]),
        );

        $report = new Report(
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
        self::assertCount(2, $complexity->decomposition);
        self::assertSame('ccn.avg', $complexity->decomposition[0]->metricKey);
        self::assertSame(12.0, $complexity->decomposition[0]->value);
        self::assertSame('cognitive.avg', $complexity->decomposition[1]->metricKey);
        self::assertSame(10.0, $complexity->decomposition[1]->value);
    }

    #[Test]
    public function itNullMetricsReturnsUnchangedReport(): void
    {
        $report = new Report(
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
                'loc.sum' => 5000,
            ]),
        );

        $finding = new Finding(
            location: new Location(RelativePath::fromString('test.php'), 1),
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('test.php'))),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('test.php')),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
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
                'loc.sum' => 10000,
            ]),
        );

        $report = new Report(
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
            ]),
        );

        $report = new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertArrayHasKey('typing', $result->healthScores);
        $typing = $result->healthScores['typing'];
        self::assertNull($typing->score);
        self::assertSame('0 classes analyzed', $typing->label);
    }

    #[Test]
    public function itTypingNotAddedWhenNoDimensions(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'ccn.avg' => 5.0,
                'loc' => 1000,
            ]),
        );

        $report = new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertSame([], $result->healthScores);
    }

}
