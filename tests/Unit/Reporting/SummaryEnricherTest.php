<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\MetricHintProvider;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\SummaryEnricher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SummaryEnricher::class)]
final class SummaryEnricherTest extends TestCase
{
    private SummaryEnricher $enricher;

    protected function setUp(): void
    {
        $this->enricher = new SummaryEnricher(
            new DebtCalculator(new RemediationTimeRegistry()),
            new MetricHintProvider(),
        );
    }

    public function testReturnsUnchangedReportWhenNoMetrics(): void
    {
        $report = new Report(
            violations: [],
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

    public function testEnrichesWithHealthScores(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.complexity' => 65.0,
                'health.cohesion' => 45.0,
                'health.coupling' => 80.0,
                'health.typing' => 90.0,
                'health.maintainability' => 58.0,
                'health.overall' => 72.0,
                'ccn.avg' => 8.2,
                'cognitive.avg' => 6.1,
                'tcc.avg' => 0.15,
                'lcom.avg' => 4.0,
            ]),
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 2.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        // Check health scores are populated
        self::assertCount(6, $result->healthScores);
        self::assertArrayHasKey('complexity', $result->healthScores);
        self::assertArrayHasKey('cohesion', $result->healthScores);
        self::assertArrayHasKey('overall', $result->healthScores);

        // Complexity is 65 > warning 50, so "Acceptable" label and no decomposition
        $complexity = $result->healthScores['complexity'];
        self::assertSame('complexity', $complexity->name);
        self::assertSame(65.0, $complexity->score);
        self::assertSame('Acceptable', $complexity->label);
        self::assertSame([], $complexity->decomposition);

        // Cohesion is 45 <= warning 50, so "Weak" and has decomposition
        $cohesion = $result->healthScores['cohesion'];
        self::assertSame(45.0, $cohesion->score);
        self::assertSame('Weak', $cohesion->label);
        self::assertCount(2, $cohesion->decomposition);
        self::assertSame('tcc.avg', $cohesion->decomposition[0]->metricKey);
        self::assertSame(0.15, $cohesion->decomposition[0]->value);
        self::assertSame('lcom.avg', $cohesion->decomposition[1]->metricKey);

        // Maintainability is 58 <= warning 65, so "Weak"
        $maintainability = $result->healthScores['maintainability'];
        self::assertSame('Weak', $maintainability->label);
    }

    public function testEnrichesWithTechDebt(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
        );

        $violation = new Violation(
            location: new Location('test.php', 1),
            symbolPath: SymbolPath::forFile('test.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [$violation, $violation],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        // complexity.cyclomatic = 30 min per violation, 2 violations = 60
        self::assertSame(60, $result->techDebtMinutes);
    }

    public function testWorstNamespaces(): void
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
                new SymbolInfo($nsSymbol, 'src/Payment', null),
            ],
            namespaceMetrics: [
                'ns:App\\Payment' => $nsMetrics,
            ],
        );

        $violation = new Violation(
            location: new Location('src/Payment/PaymentService.php', 42),
            symbolPath: SymbolPath::forClass('App\\Payment', 'PaymentService'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [$violation],
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

    public function testWorstClasses(): void
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
                new SymbolInfo($classSymbol, 'src/Service/PaymentService.php', 10),
            ],
            classMetrics: [
                'class:App\\Service\\PaymentService' => $classMetrics,
            ],
        );

        $report = new Report(
            violations: [],
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
        self::assertSame('src/Service/PaymentService.php', $cls->file);
        self::assertSame(0, $cls->classCount);
        self::assertArrayHasKey('methodCount', $cls->metrics);
        self::assertSame(32, $cls->metrics['methodCount']);
    }

    public function testSkipsSymbolsAboveWarningThreshold(): void
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
                new SymbolInfo($classSymbol, 'src/Service/GoodService.php', 1),
            ],
            classMetrics: [
                'class:App\\Service\\GoodService' => $classMetrics,
            ],
        );

        $report = new Report(
            violations: [],
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

    public function testPreservesOriginalReportFields(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
        );

        $violation = new Violation(
            location: new Location('test.php', 1),
            symbolPath: SymbolPath::forFile('test.php'),
            ruleName: 'test',
            violationCode: 'test',
            message: 'test message',
            severity: Severity::Warning,
        );

        $report = new Report(
            violations: [$violation],
            filesAnalyzed: 42,
            filesSkipped: 3,
            duration: 5.5,
            errorCount: 0,
            warningCount: 1,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->violations);
        self::assertSame(42, $result->filesAnalyzed);
        self::assertSame(3, $result->filesSkipped);
        self::assertSame(5.5, $result->duration);
        self::assertSame(0, $result->errorCount);
        self::assertSame(1, $result->warningCount);
        self::assertSame($metrics, $result->metrics);
    }

    public function testHealthScoresEmptyWhenNoProjectHealthMetrics(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'ccn.avg' => 5.0,
                'loc' => 1000,
            ]),
        );

        $report = new Report(
            violations: [],
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

    public function testDecompositionShownWhenScoreBelowWarning(): void
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
            violations: [],
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

    public function testPartialAnalysisReturnsUnchangedReport(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
                'health.complexity' => 65.0,
            ]),
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report, partialAnalysis: true);

        self::assertSame($report, $result);
        self::assertSame([], $result->healthScores);
    }

    public function testDebtPer1kLocComputedCorrectly(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
                'loc.sum' => 5000,
            ]),
        );

        $violation = new Violation(
            location: new Location('test.php', 1),
            symbolPath: SymbolPath::forFile('test.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'test',
            severity: Severity::Error,
        );

        $report = new Report(
            violations: [$violation, $violation],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        // 2 violations * 30 min = 60 min total debt, 5000 LOC = 5 kLOC
        // debtPer1kLoc = 60 / 5 = 12.0
        self::assertSame(12.0, $result->debtPer1kLoc);
    }

    public function testDebtPer1kLocZeroWhenNoViolations(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 85.0,
                'loc.sum' => 10000,
            ]),
        );

        $report = new Report(
            violations: [],
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

    public function testDebtPer1kLocNullWhenNoLoc(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.overall' => 72.0,
            ]),
        );

        $report = new Report(
            violations: [],
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

    public function testTypingNAWhenOtherDimensionsExist(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.complexity' => 65.0,
                'health.overall' => 72.0,
            ]),
        );

        $report = new Report(
            violations: [],
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

    public function testTypingNotAddedWhenNoDimensions(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'ccn.avg' => 5.0,
                'loc' => 1000,
            ]),
        );

        $report = new Report(
            violations: [],
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

    /**
     * @param list<SymbolInfo> $namespaces
     * @param array<string, MetricBag> $namespaceMetrics
     * @param list<SymbolInfo> $classes
     * @param array<string, MetricBag> $classMetrics
     */
    private function createMetricRepository(
        MetricBag $projectMetrics,
        array $namespaces = [],
        array $namespaceMetrics = [],
        array $classes = [],
        array $classMetrics = [],
    ): MetricRepositoryInterface {
        $mock = $this->createMock(MetricRepositoryInterface::class);

        $mock->method('get')
            ->willReturnCallback(function (SymbolPath $symbol) use ($projectMetrics, $namespaceMetrics, $classMetrics): MetricBag {
                $canonical = $symbol->toCanonical();

                if ($symbol->getType() === SymbolType::Project) {
                    return $projectMetrics;
                }

                if (isset($namespaceMetrics[$canonical])) {
                    return $namespaceMetrics[$canonical];
                }

                if (isset($classMetrics[$canonical])) {
                    return $classMetrics[$canonical];
                }

                return new MetricBag();
            });

        $mock->method('all')
            ->willReturnCallback(function (SymbolType $type) use ($namespaces, $classes): iterable {
                return match ($type) {
                    SymbolType::Namespace_ => $namespaces,
                    SymbolType::Class_ => $classes,
                    default => [],
                };
            });

        return $mock;
    }
}
