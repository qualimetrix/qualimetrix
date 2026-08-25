<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummary;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Report;

/**
 * Tests finding density computation in worst offenders.
 */
#[CoversClass(HealthSummaryBuilder::class)]
#[CoversClass(WorstOffender::class)]
final class ViolationDensityTest extends TestCase
{
    use MetricRepositoryTestHelper;
    private HealthSummaryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        );
    }

    #[Test]
    public function itClassDensityComputedCorrectly(): void
    {
        // 200-line class with 10 findings => density = 10/200*100 = 5.0
        $classSymbol = SymbolPath::forClass('App\\Service', 'HeavyService');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 30.0,
            'health.complexity' => 25.0,
            'classLoc' => 200,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, RelativePath::fromString('src/Service/HeavyService.php'), 1)],
            classMetrics: ['class:App\\Service\\HeavyService' => $classMetrics],
        );

        $findings = $this->createFindingsForClass('App\\Service', 'HeavyService', 10);

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 10,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->summarize($report);

        self::assertCount(1, $result->worstClasses);
        self::assertSame(5.0, $result->worstClasses[0]->violationDensity);
    }

    #[Test]
    public function itNamespaceDensityUsesLocSum(): void
    {
        // Namespace with 1000 total LOC and 5 findings => density = 5/1000*100 = 0.5
        $nsSymbol = SymbolPath::forNamespace('App\\Payment');
        $nsMetrics = MetricBag::fromArray([
            'health.overall' => 40.0,
            'health.complexity' => 35.0,
            'classCount.sum' => 3,
            'loc.sum' => 1000,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            namespaces: [new SymbolInfo($nsSymbol, RelativePath::fromString('src/Payment'), null)],
            namespaceMetrics: ['ns:App\\Payment' => $nsMetrics],
        );

        $findings = $this->createFindingsForClass('App\\Payment', 'PaymentService', 5);

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 5,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->summarize($report);

        self::assertCount(1, $result->worstNamespaces);
        self::assertSame(0.5, $result->worstNamespaces[0]->violationDensity);
    }

    #[Test]
    public function itDensityZeroWhenNoFindings(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'CleanService');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 80.0,
            'health.complexity' => 75.0,
            'classLoc' => 500,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 80.0]),
            classes: [new SymbolInfo($classSymbol, RelativePath::fromString('src/Service/CleanService.php'), 1)],
            classMetrics: ['class:App\\Service\\CleanService' => $classMetrics],
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

        $result = $this->summarize($report);

        self::assertCount(1, $result->worstClasses);
        self::assertSame(0.0, $result->worstClasses[0]->violationDensity);
    }

    #[Test]
    public function itDensityNullWhenLocZero(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'EmptyClass');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 50.0,
            'health.complexity' => 45.0,
            'classLoc' => 0,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, RelativePath::fromString('src/Service/EmptyClass.php'), 1)],
            classMetrics: ['class:App\\Service\\EmptyClass' => $classMetrics],
        );

        $findings = $this->createFindingsForClass('App\\Service', 'EmptyClass', 3);

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 3,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->summarize($report);

        self::assertCount(1, $result->worstClasses);
        self::assertNull($result->worstClasses[0]->violationDensity);
    }

    #[Test]
    public function itDensityNullWhenLocMissing(): void
    {
        $classSymbol = SymbolPath::forClass('App\\Service', 'NoLocClass');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 50.0,
            'health.complexity' => 45.0,
            // No 'loc' metric
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, RelativePath::fromString('src/Service/NoLocClass.php'), 1)],
            classMetrics: ['class:App\\Service\\NoLocClass' => $classMetrics],
        );

        $findings = $this->createFindingsForClass('App\\Service', 'NoLocClass', 2);

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->summarize($report);

        self::assertCount(1, $result->worstClasses);
        self::assertNull($result->worstClasses[0]->violationDensity);
    }

    #[Test]
    public function itDensityRoundedToOneDecimal(): void
    {
        // 300-line class with 7 findings => density = 7/300*100 = 2.333... => 2.3
        $classSymbol = SymbolPath::forClass('App\\Service', 'OddClass');
        $classMetrics = MetricBag::fromArray([
            'health.overall' => 35.0,
            'health.complexity' => 30.0,
            'classLoc' => 300,
        ]);

        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 60.0]),
            classes: [new SymbolInfo($classSymbol, RelativePath::fromString('src/Service/OddClass.php'), 1)],
            classMetrics: ['class:App\\Service\\OddClass' => $classMetrics],
        );

        $findings = $this->createFindingsForClass('App\\Service', 'OddClass', 7);

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 7,
            warningCount: 0,
            metrics: $metrics,
        );

        $result = $this->summarize($report);

        self::assertCount(1, $result->worstClasses);
        self::assertSame(2.3, $result->worstClasses[0]->violationDensity);
    }

    #[Test]
    public function itWorstOffenderDefaultDensityIsNull(): void
    {
        $offender = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'Test'),
            file: null,
            healthOverall: 50.0,
            label: 'Test',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 0,
            ),
        );

        self::assertNull($offender->violationDensity);
    }

    /**
     * @return list<Finding>
     */
    private function createFindingsForClass(string $namespace, string $class, int $count): array
    {
        $findings = [];
        for ($i = 0; $i < $count; $i++) {
            $findings[] = new Finding(
                location: new Location(RelativePath::fromString("src/{$class}.php"), $i + 1),
                subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass($namespace, $class), RelativePath::fromString("src/{$class}.php"), DeclarationOrdinal::fromRank(0))),
                symbolPath: SymbolPath::forClass($namespace, $class),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: "test violation {$i}",
                severity: Severity::Error,
            );
        }

        return $findings;
    }

    private function summarize(Report $report): HealthSummary
    {
        $metrics = $report->metrics ?? throw new LogicException('Metrics are required.');

        return $this->builder->build($metrics, new NamespaceTree($metrics->getNamespaces()), $report->findings);
    }

}
