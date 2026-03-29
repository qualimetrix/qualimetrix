<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Health\WorstOffender;
use Qualimetrix\Reporting\Impact\ClassRankResolver;
use Qualimetrix\Reporting\Impact\ImpactCalculator;
use Qualimetrix\Reporting\Report;

/**
 * Tests violation density computation in worst offenders.
 */
#[CoversClass(SummaryEnricher::class)]
#[CoversClass(WorstOffender::class)]
final class ViolationDensityTest extends TestCase
{
    use MetricRepositoryTestHelper;
    private SummaryEnricher $enricher;

    protected function setUp(): void
    {
        $registry = new RemediationTimeRegistry();
        $this->enricher = new SummaryEnricher(
            new DebtCalculator($registry),
            new MetricHintProvider(),
            new ImpactCalculator(new ClassRankResolver(), $registry),
        );
    }

    public function testClassDensityComputedCorrectly(): void
    {
        // 200-line class with 10 violations => density = 10/200*100 = 5.0
        $classSymbol = SymbolPath::forClass('App\\Service', 'HeavyService');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 30.0,
            'health.complexity' => 25.0,
            'classLoc' => 200,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, 'src/Service/HeavyService.php', 1)],
            classMetrics: ['class:App\\Service\\HeavyService' => $classMetrics],
        );

        $violations = $this->createViolationsForClass('App\\Service', 'HeavyService', 10);

        $report = new Report(
            violations: $violations,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 10,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->worstClasses);
        self::assertSame(5.0, $result->worstClasses[0]->violationDensity);
    }

    public function testNamespaceDensityUsesLocSum(): void
    {
        // Namespace with 1000 total LOC and 5 violations => density = 5/1000*100 = 0.5
        $nsSymbol = SymbolPath::forNamespace('App\\Payment');
        $nsMetrics = MetricBag::fromArray([
            'health.overall' => 40.0,
            'health.complexity' => 35.0,
            'classCount.sum' => 3,
            'loc.sum' => 1000,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            namespaces: [new SymbolInfo($nsSymbol, 'src/Payment', null)],
            namespaceMetrics: ['ns:App\\Payment' => $nsMetrics],
        );

        $violations = $this->createViolationsForClass('App\\Payment', 'PaymentService', 5);

        $report = new Report(
            violations: $violations,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 5,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->worstNamespaces);
        self::assertSame(0.5, $result->worstNamespaces[0]->violationDensity);
    }

    public function testDensityZeroWhenNoViolations(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'CleanService');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 80.0,
            'health.complexity' => 75.0,
            'classLoc' => 500,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 80.0]),
            classes: [new SymbolInfo($classSymbol, 'src/Service/CleanService.php', 1)],
            classMetrics: ['class:App\\Service\\CleanService' => $classMetrics],
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
        self::assertSame(0.0, $result->worstClasses[0]->violationDensity);
    }

    public function testDensityNullWhenLocZero(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'EmptyClass');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 50.0,
            'health.complexity' => 45.0,
            'classLoc' => 0,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, 'src/Service/EmptyClass.php', 1)],
            classMetrics: ['class:App\\Service\\EmptyClass' => $classMetrics],
        );

        $violations = $this->createViolationsForClass('App\\Service', 'EmptyClass', 3);

        $report = new Report(
            violations: $violations,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 3,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->worstClasses);
        self::assertNull($result->worstClasses[0]->violationDensity);
    }

    public function testDensityNullWhenLocMissing(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'NoLocClass');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 50.0,
            'health.complexity' => 45.0,
            // No 'loc' metric
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, 'src/Service/NoLocClass.php', 1)],
            classMetrics: ['class:App\\Service\\NoLocClass' => $classMetrics],
        );

        $violations = $this->createViolationsForClass('App\\Service', 'NoLocClass', 2);

        $report = new Report(
            violations: $violations,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->worstClasses);
        self::assertNull($result->worstClasses[0]->violationDensity);
    }

    public function testDensityRoundedToOneDecimal(): void
    {
        // 300-line class with 7 violations => density = 7/300*100 = 2.333... => 2.3
        $classSymbol = SymbolPath::forClass('App\\Service', 'OddClass');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 35.0,
            'health.complexity' => 30.0,
            'classLoc' => 300,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, 'src/Service/OddClass.php', 1)],
            classMetrics: ['class:App\\Service\\OddClass' => $classMetrics],
        );

        $violations = $this->createViolationsForClass('App\\Service', 'OddClass', 7);

        $report = new Report(
            violations: $violations,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 7,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->enricher->enrich($report);

        self::assertCount(1, $result->worstClasses);
        self::assertSame(2.3, $result->worstClasses[0]->violationDensity);
    }

    public function testWorstOffenderDefaultDensityIsNull(): void
    {
        $offender = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'Test'),
            file: null,
            healthOverall: 50.0,
            label: 'Test',
            reason: '',
            violationCount: 5,
            classCount: 0,
        );

        self::assertNull($offender->violationDensity);
    }

    /**
     * @return list<Violation>
     */
    private function createViolationsForClass(string $namespace, string $class, int $count): array
    {
        $violations = [];
        for ($i = 0; $i < $count; $i++) {
            $violations[] = new Violation(
                location: new Location("src/{$class}.php", $i + 1),
                symbolPath: SymbolPath::forClass($namespace, $class),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: "test violation {$i}",
                severity: Severity::Error,
            );
        }

        return $violations;
    }

}
