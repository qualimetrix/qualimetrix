<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\HealthScoreDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Filter\FindingFilter;
use Qualimetrix\Reporting\Formatter\Json\JsonFindingSection;
use Qualimetrix\Reporting\Formatter\Json\JsonFormatter;
use Qualimetrix\Reporting\Formatter\Json\JsonHealthSection;
use Qualimetrix\Reporting\Formatter\Json\JsonOffenderSection;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(JsonFormatter::class)]
final class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        $hintProvider = new HealthMetricCatalog();
        $definitionCatalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $namespaceDrillDown = new HealthScoreDrillDown($hintProvider, $definitionCatalog);
        $worstClassDrillDown = new WorstClassDrillDown($definitionCatalog);
        $sanitizer = new JsonSanitizer();
        $findingFilter = new FindingFilter();
        $remediationTimeRegistry = new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues());
        $this->formatter = new JsonFormatter(
            new DebtCalculator($remediationTimeRegistry),
            new JsonHealthSection(new HealthScoreResolver($namespaceDrillDown), $sanitizer),
            new JsonOffenderSection($worstClassDrillDown, $findingFilter, $sanitizer),
            new JsonFindingSection($remediationTimeRegistry, $sanitizer),
        );
    }

    #[Test]
    public function itReturnsJsonName(): void
    {
        self::assertSame('json', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsDefaultGroupByNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itReturnsValidJson(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertJson($output);
    }

    #[Test]
    public function itFormatsEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Meta section
        self::assertArrayHasKey('version', $data['meta']);
        self::assertIsString($data['meta']['version']);
        self::assertSame('qmx', $data['meta']['package']);
        self::assertArrayHasKey('timestamp', $data['meta']);

        // Summary
        self::assertSame(42, $data['summary']['filesAnalyzed']);
        self::assertSame(0, $data['summary']['filesSkipped']);
        self::assertSame(0.15, $data['summary']['duration']);
        self::assertSame(0, $data['summary']['violationCount']);
        self::assertSame(0, $data['summary']['errorCount']);
        self::assertSame(0, $data['summary']['warningCount']);
        self::assertSame(0, $data['summary']['techDebtMinutes']);

        // Empty sections
        self::assertNull($data['health']);
        self::assertSame([], $data['worstNamespaces']);
        self::assertSame([], $data['worstClasses']);
        self::assertSame([], $data['violations']);
    }

    #[Test]
    public function itProducesIso8601Timestamp(): void
    {
        $report = new Report([], 0, 0, 0.0, 0, 0);
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $timestamp = $data['meta']['timestamp'];
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
    }

    #[Test]
    public function itFormatsReportWithFindings(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity of 25 exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 25,
                threshold: 10,
                recommendation: 'Cyclomatic complexity: 25 (threshold: 10) — too many code paths',
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity of 12 exceeds threshold of 10',
                severity: Severity::Warning,
                metricValue: 12,
                threshold: 10,
                recommendation: 'Cyclomatic complexity: 12 (threshold: 10) — too many code paths',
            ))
            ->filesAnalyzed(42)
            ->filesSkipped(1)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Findings are flat list, sorted by severity (error first)
        self::assertCount(2, $data['violations']);

        $v1 = $data['violations'][0];
        self::assertSame('src/Service/UserService.php', $v1['file']);
        self::assertSame(42, $v1['line']);
        self::assertSame('App\Service\UserService::calculateDiscount', $v1['symbol']);
        self::assertSame('App\Service', $v1['namespace']);
        self::assertSame('complexity.cyclomatic', $v1['rule']);
        self::assertSame('complexity.cyclomatic', $v1['code']);
        self::assertSame('error', $v1['severity']);
        self::assertSame('Cyclomatic complexity of 25 exceeds threshold of 10', $v1['message']);
        self::assertSame(25, $v1['metricValue']);
        self::assertSame(10, $v1['threshold']);

        $v2 = $data['violations'][1];
        self::assertSame(120, $v2['line']);
        self::assertSame('warning', $v2['severity']);

        // Summary
        self::assertSame(42, $data['summary']['filesAnalyzed']);
        self::assertSame(1, $data['summary']['filesSkipped']);
        self::assertSame(2, $data['summary']['violationCount']);
        self::assertSame(1, $data['summary']['errorCount']);
        self::assertSame(1, $data['summary']['warningCount']);
    }

    #[Test]
    public function itUsesDisplayMessageFallbackForFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                code: 'test',
                message: 'Technical message only',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // message field always uses the raw finding message
        self::assertSame('Technical message only', $data['violations'][0]['message']);
    }

    #[Test]
    public function itIncludesHealthScores(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'complexity' => new HealthScore(
                    name: 'complexity',
                    score: 65.0,
                    label: 'Fair',
                    warningThreshold: 50.0,
                    errorThreshold: 25.0,
                    decomposition: [
                        new DecompositionItem(
                            metricKey: 'ccn.avg',
                            humanName: 'Cyclomatic (avg)',
                            value: 8.2,
                            goodValue: 'below 4',
                            direction: 'lower_is_better',
                            explanation: 'too many code paths per method',
                        ),
                    ],
                ),
                'cohesion' => new HealthScore(
                    name: 'cohesion',
                    score: 45.0,
                    label: 'Poor',
                    warningThreshold: 50.0,
                    errorThreshold: 25.0,
                ),
            ],
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotNull($data['health']);
        self::assertArrayHasKey('complexity', $data['health']);
        self::assertArrayHasKey('cohesion', $data['health']);

        $complexity = $data['health']['complexity'];
        self::assertEquals(65.0, $complexity['score']);
        self::assertSame('Fair', $complexity['label']);
        self::assertEquals(50.0, $complexity['threshold']['warning']);
        self::assertEquals(25.0, $complexity['threshold']['error']);

        // Decomposition always included in JSON
        self::assertCount(1, $complexity['decomposition']);
        $d = $complexity['decomposition'][0];
        self::assertSame('ccn.avg', $d['metric']);
        self::assertSame('Cyclomatic (avg)', $d['humanName']);
        self::assertSame(8.2, $d['value']);
        self::assertSame('below 4', $d['good']);
        self::assertSame('lower_is_better', $d['direction']);

        // Cohesion has empty decomposition
        self::assertSame([], $data['health']['cohesion']['decomposition']);
    }

    #[Test]
    public function itShowsHealthInScopedReporting(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 8,
            filesSkipped: 0,
            duration: 0.4,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'complexity' => new HealthScore('complexity', 65.0, 'Fair', 50.0, 25.0),
            ],
        );

        $context = new FormatterContext(scopedReporting: true);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Health is always shown now — full graph is always available
        self::assertNotNull($data['health']);
        self::assertArrayHasKey('complexity', $data['health']);
    }

    #[Test]
    public function itIncludesWorstNamespaces(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: [
                new WorstOffender(
                    symbolPath: SymbolPath::forNamespace('App\Payment'),
                    file: null,
                    healthOverall: 31.0,
                    label: 'Critical',
                    reason: 'low cohesion, high complexity',
                    evidence: new WorstOffenderEvidence(
                        violationCount: 12,
                        classCount: 4,
                        metrics: ['cbo.avg' => 8.5],
                        healthScores: ['complexity' => 28.0, 'cohesion' => 25.0],
                    ),
                ),
            ],
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['worstNamespaces']);
        $ns = $data['worstNamespaces'][0];
        self::assertSame('App\Payment', $ns['symbolPath']);
        self::assertEquals(31.0, $ns['healthOverall']);
        self::assertSame('Critical', $ns['label']);
        self::assertSame('low cohesion, high complexity', $ns['reason']);
        self::assertSame(12, $ns['violationCount']);
        self::assertSame(4, $ns['classCount']);
        self::assertArrayNotHasKey('file', $ns);
        self::assertArrayNotHasKey('metrics', $ns);
        self::assertEquals(['complexity' => 28.0, 'cohesion' => 25.0], $ns['healthScores']);
    }

    #[Test]
    public function itIncludesWorstClasses(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [
                new WorstOffender(
                    symbolPath: SymbolPath::forClass('App\Payment', 'PaymentService'),
                    file: RelativePath::fromString('src/Payment/PaymentService.php'),
                    healthOverall: 28.0,
                    label: 'Critical',
                    reason: '32 methods, high coupling',
                    evidence: new WorstOffenderEvidence(
                        violationCount: 5,
                        classCount: 0,
                        metrics: ['methodCount' => 32, 'cbo' => 18],
                        healthScores: ['complexity' => 12.0, 'cohesion' => 8.0],
                    ),
                ),
            ],
        );

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['worstClasses']);
        $cls = $data['worstClasses'][0];
        self::assertSame('App\Payment\PaymentService', $cls['symbolPath']);
        self::assertSame('src/Payment/PaymentService.php', $cls['file']);
        self::assertEquals(28.0, $cls['healthOverall']);
        self::assertSame('Critical', $cls['label']);
        self::assertSame(5, $cls['violationCount']);
        self::assertArrayNotHasKey('classCount', $cls);
        self::assertSame(['methodCount' => 32, 'cbo' => 18], $cls['metrics']);
        self::assertEquals(['complexity' => 12.0, 'cohesion' => 8.0], $cls['healthScores']);
    }

    #[Test]
    public function itIncludesAllFindingsByDefault(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(55, $data['violations']);
        // Summary shows total count
        self::assertSame(55, $data['summary']['violationCount']);
    }

    #[Test]
    public function itShowsAllFindingsWhenDetailEnabled(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $context = new FormatterContext(detailLimit: 0);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(55, $data['violations']);
    }

    #[Test]
    public function itOverridesDetailWithFormatOptViolations(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 20; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();

        // --detail + --format-opt violations=5 → explicit opt wins
        $context = new FormatterContext(detailLimit: 0, options: ['violations' => '5']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(5, $data['violations']);
    }

    #[Test]
    public function itShowsNoViolationsWhenFormatOptViolationsIsZero(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: 'Test',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(options: ['violations' => '0']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([], $data['violations']);
        self::assertSame(1, $data['summary']['violationCount']);
    }

    #[Test]
    public function itShowsAllViolationsWhenFormatOptViolationsIsAll(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $context = new FormatterContext(options: ['violations' => 'all']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(55, $data['violations']);
    }

    #[Test]
    public function itFiltersFindingsByNamespace(): void
    {
        // Findings are pre-filtered by ResultPresenter before reaching the formatter.
        // Only in-scope findings are passed to the report builder.
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Payment/Pay.php'), 10),
                symbolPath: SymbolPath::forClass('App\Payment', 'PayService'),
                ruleName: 'test',
                code: 'test',
                message: 'In Payment',
                severity: Severity::Error,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Payment/Gateway/Stripe.php'), 20),
                symbolPath: SymbolPath::forClass('App\Payment\Gateway', 'Stripe'),
                ruleName: 'test',
                code: 'test',
                message: 'In Payment Gateway',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(namespace: 'App\Payment');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Only Payment findings (boundary-aware: App\Payment and App\Payment\Gateway)
        self::assertCount(2, $data['violations']);
        self::assertSame('In Payment Gateway', $data['violations'][0]['message']);
        self::assertSame('In Payment', $data['violations'][1]['message']);

        // Summary reflects filtered set
        self::assertSame(2, $data['summary']['violationCount']);
        self::assertSame(1, $data['summary']['errorCount']);
        self::assertSame(1, $data['summary']['warningCount']);
        // filesAnalyzed stays global
        self::assertSame(3, $data['summary']['filesAnalyzed']);
        // techDebtMinutes recalculated for filtered findings (2 × 15min default)
        self::assertSame(30, $data['summary']['techDebtMinutes']);
    }

    #[Test]
    public function itFiltersFindingsByNamespaceBoundaryAware(): void
    {
        // App\PaymentGateway is NOT in App\Payment scope — ResultPresenter
        // would filter it out before reaching the formatter. Empty report.
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(namespace: 'App\Payment');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([], $data['violations']);
    }

    #[Test]
    public function itFiltersFindingsByClass(): void
    {
        // Only the matching class finding is passed (pre-filtered by ResultPresenter)
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Payment/Pay.php'), 10),
                symbolPath: SymbolPath::forMethod('App\Payment', 'PayService', 'process'),
                ruleName: 'test',
                code: 'test',
                message: 'In PayService',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(class: 'App\Payment\PayService');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['violations']);
        self::assertSame('In PayService', $data['violations'][0]['message']);

        // Summary reflects filtered set
        self::assertSame(1, $data['summary']['violationCount']);
    }

    #[Test]
    public function itProducesNullForNanAndInfMetricValues(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'maintainability.index',
                code: 'maintainability.index',
                message: 'MI is NaN',
                severity: Severity::Warning,
                metricValue: \NAN,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'maintainability.index',
                code: 'maintainability.index',
                message: 'MI is INF',
                severity: Severity::Warning,
                metricValue: \INF,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        // Should produce valid JSON (NaN/INF would break json_encode)
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertNull($data['violations'][0]['metricValue']);
        self::assertNull($data['violations'][1]['metricValue']);
    }

    #[Test]
    public function itSortsFindingsByCanonicalIdentity(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test.a',
                message: 'Warning low exceedance',
                severity: Severity::Warning,
                metricValue: 11,
                threshold: 10,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                code: 'test.b',
                message: 'Error low exceedance',
                severity: Severity::Error,
                metricValue: 11,
                threshold: 10,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/C.php'), 30),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'test',
                code: 'test.c',
                message: 'Warning high exceedance',
                severity: Severity::Warning,
                metricValue: 50,
                threshold: 10,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/D.php'), 40),
                symbolPath: SymbolPath::forClass('App', 'D'),
                ruleName: 'test',
                code: 'test.d',
                message: 'Error high exceedance',
                severity: Severity::Error,
                metricValue: 50,
                threshold: 10,
            ))
            ->filesAnalyzed(4)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('test.a', $data['violations'][0]['code']);
        self::assertSame('test.b', $data['violations'][1]['code']);
        self::assertSame('test.c', $data['violations'][2]['code']);
        self::assertSame('test.d', $data['violations'][3]['code']);
    }

    #[Test]
    public function itRendersInfoSeverityWithoutUsingItAsTheSortKey(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'architecture.coverage',
                code: 'architecture.coverage',
                message: 'Class not assigned to a layer',
                severity: Severity::Info,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), 1),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Complexity 12',
                severity: Severity::Warning,
                metricValue: 12,
                threshold: 10,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/C.php'), 1),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Complexity 25',
                severity: Severity::Error,
                metricValue: 25,
                threshold: 20,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // JSON severity uses the enum value ('info', 'warning', 'error')
        $severities = array_map(static fn(array $v): string => $v['severity'], $data['violations']);
        self::assertSame(['info', 'warning', 'error'], $severities);

        // Find the info finding and verify its rendered string
        $infoFinding = null;
        foreach ($data['violations'] as $finding) {
            if ($finding['severity'] === 'info') {
                $infoFinding = $finding;
                break;
            }
        }
        self::assertNotNull($infoFinding);
        self::assertSame('info', $infoFinding['severity']);
    }

    #[Test]
    public function itSortsNullThresholdFindingsByIdentity(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'code-smell',
                code: 'code-smell.eval',
                message: 'No threshold',
                severity: Severity::Warning,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: 'Has threshold',
                severity: Severity::Warning,
                metricValue: 20,
                threshold: 10,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('code-smell.eval', $data['violations'][0]['code']);
        self::assertSame('test', $data['violations'][1]['code']);
    }

    #[Test]
    public function itFormatsNamespaceLevelFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php')),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'size.namespace',
                code: 'size.namespace',
                message: 'Namespace too large',
                severity: Severity::Error,
                metricValue: 16,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $v = $data['violations'][0];
        self::assertNull($v['line']);
        self::assertSame('App\Service', $v['symbol']);
        self::assertSame('App\Service', $v['namespace']);
    }

    #[Test]
    public function itFormatsFilelessFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App'),
                ruleName: 'architecture.circular',
                code: 'architecture.circular',
                message: 'Circular dependency detected',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $v = $data['violations'][0];
        self::assertNull($v['file']);
        self::assertNull($v['line']);
    }

    #[Test]
    public function itLimitsWorstNamespacesWithTopNOption(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: [
                new WorstOffender(SymbolPath::forNamespace('App\A'), null, 20.0, 'Critical', 'bad', new WorstOffenderEvidence(5, 3)),
                new WorstOffender(SymbolPath::forNamespace('App\B'), null, 25.0, 'Critical', 'bad', new WorstOffenderEvidence(3, 2)),
                new WorstOffender(SymbolPath::forNamespace('App\C'), null, 30.0, 'Critical', 'bad', new WorstOffenderEvidence(2, 1)),
            ],
        );

        $context = new FormatterContext(options: ['top' => '2']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['worstNamespaces']);
    }

    #[Test]
    public function itSanitizesNanInHealthScores(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'test' => new HealthScore(
                    name: 'test',
                    score: \NAN,
                    label: 'Unknown',
                    warningThreshold: 50.0,
                    errorThreshold: 25.0,
                    decomposition: [
                        new DecompositionItem('metric', 'Test', \INF, 'below 10', 'lower_is_better', 'bad'),
                    ],
                ),
            ],
        );

        // Should not throw — NaN/INF are sanitized to null
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertNull($data['health']['test']['score']);
        self::assertNull($data['health']['test']['decomposition'][0]['value']);
    }

    #[Test]
    public function itProducesNullForEmptyHealthScores(): void
    {
        $report = new Report([], 10, 0, 0.5, 0, 0);

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertNull($data['health']);
    }

    #[Test]
    public function itFiltersWorstOffendersByNamespace(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: [
                new WorstOffender(SymbolPath::forNamespace('App\Payment'), null, 30.0, 'Critical', 'bad', new WorstOffenderEvidence(5, 3)),
                new WorstOffender(SymbolPath::forNamespace('App\Payment\Gateway'), null, 25.0, 'Critical', 'bad', new WorstOffenderEvidence(3, 2)),
                new WorstOffender(SymbolPath::forNamespace('App\User'), null, 35.0, 'Critical', 'bad', new WorstOffenderEvidence(2, 1)),
            ],
        );

        $context = new FormatterContext(namespace: 'App\Payment');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['worstNamespaces']);
        self::assertSame('App\Payment', $data['worstNamespaces'][0]['symbolPath']);
        self::assertSame('App\Payment\Gateway', $data['worstNamespaces'][1]['symbolPath']);
    }

    #[Test]
    public function itFallsBackToDefaultForInvalidViolationsOption(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $context = new FormatterContext(options: ['violations' => 'invalid']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Invalid value falls back to default (no limit)
        self::assertCount(55, $data['violations']);
    }

    #[Test]
    public function itShowsOffendersInScopedReporting(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 8,
            filesSkipped: 0,
            duration: 0.4,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: [
                new WorstOffender(SymbolPath::forNamespace('App'), null, 30.0, 'Critical', 'bad', new WorstOffenderEvidence(5, 3)),
            ],
            worstClasses: [
                new WorstOffender(SymbolPath::forClass('App', 'Foo'), RelativePath::fromString('src/Foo.php'), 20.0, 'Critical', 'bad', new WorstOffenderEvidence(1, 0)),
            ],
        );

        $context = new FormatterContext(scopedReporting: true);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Scoped reporting: offenders and health are always shown (full graph available)
        self::assertNotEmpty($data['worstNamespaces']);
        self::assertNotEmpty($data['worstClasses']);
    }

    #[Test]
    public function itSortsNanMetricValueByIdentity(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test.a',
                message: 'NaN metric',
                severity: Severity::Warning,
                metricValue: \NAN,
                threshold: 10,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                code: 'test.b',
                message: 'Normal metric',
                severity: Severity::Warning,
                metricValue: 25,
                threshold: 10,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['violations']);
        self::assertSame('test.a', $data['violations'][0]['code']);
        self::assertSame('test.b', $data['violations'][1]['code']);
    }

    #[Test]
    public function itShowsNamespaceHealthScoresWithNamespaceFilter(): void
    {
        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag::fromArray([
            'health.overall' => 40.0,
            'health.complexity' => 55.0,
            'health.cohesion' => 25.0,
            'classCount' => 5,
        ]);

        $metrics = self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturnCallback(
            static fn(SymbolPath $sp): bool => $sp->toCanonical() === $nsPath->toCanonical(),
        );
        $metrics->method('get')->willReturnCallback(
            static fn(SymbolPath $sp): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag => $sp->toCanonical() === $nsPath->toCanonical()
                ? $nsMetrics
                : new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag(),
        );
        $metrics->method('all')->willReturnCallback(
            static fn(\Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel $level): array => $level === \Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel::Namespace_
                ? [new \Qualimetrix\Core\Symbol\SymbolInfo($nsPath, \Qualimetrix\Core\Path\RelativePath::fromString('src/Service'), 0)]
                : [],
        );

        $report = new Report(
            findings: [],
            filesAnalyzed: 50,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Fair', 50.0, 30.0),
            ],
        );

        $context = new FormatterContext(namespace: 'App\Service');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Should use namespace-level scores, not project-level
        self::assertNotNull($data['health']);
        self::assertArrayHasKey('overall', $data['health']);
        self::assertEquals(40.0, $data['health']['overall']['score']);
        self::assertArrayHasKey('complexity', $data['health']);
        self::assertEquals(55.0, $data['health']['complexity']['score']);
        self::assertArrayHasKey('cohesion', $data['health']);
        self::assertEquals(25.0, $data['health']['cohesion']['score']);
    }

    #[Test]
    public function itBuildsWorstClassesFromMetricsWithNamespaceFilter(): void
    {
        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classMetrics = \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag::fromArray([
            'health.overall' => 25.0,
            'health.complexity' => 20.0,
            'health.cohesion' => 15.0,
            'methodCount' => 32,
            'cbo' => 18,
        ]);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag::fromArray([
            'health.overall' => 40.0,
        ]);

        $metrics = self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturnCallback(
            static fn(SymbolPath $sp): bool => $sp->toCanonical() === $nsPath->toCanonical(),
        );
        $metrics->method('get')->willReturnCallback(
            static fn(SymbolPath $sp): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag => match ($sp->toCanonical()) {
                $nsPath->toCanonical() => $nsMetrics,
                $classPath->toCanonical() => $classMetrics,
                default => new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag(),
            },
        );
        $metrics->method('all')->willReturnCallback(
            static fn(\Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel $level): array => $level === \Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel::Class_
                ? [new \Qualimetrix\Core\Symbol\SymbolInfo($classPath, \Qualimetrix\Core\Path\RelativePath::fromString('src/Service/UserService.php'), 1)]
                : [],
        );

        $report = new Report(
            findings: [],
            filesAnalyzed: 50,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Fair', 50.0, 30.0),
            ],
            worstClasses: [],
        );

        $context = new FormatterContext(namespace: 'App\Service');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Should build worst classes from namespace classes
        self::assertCount(1, $data['worstClasses']);
        self::assertSame('App\Service\UserService', $data['worstClasses'][0]['symbolPath']);
        self::assertEquals(25.0, $data['worstClasses'][0]['healthOverall']);
        self::assertSame('src/Service/UserService.php', $data['worstClasses'][0]['file']);
        self::assertSame(32, $data['worstClasses'][0]['metrics']['methodCount']);
    }

    #[Test]
    public function itReturnsNullHealthWhenNoNsMetricsWithNamespaceFilter(): void
    {
        $metrics = self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturn(false);
        $metrics->method('get')->willReturn(new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag());
        $metrics->method('all')->willReturn([]);

        $report = new Report(
            findings: [],
            filesAnalyzed: 50,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Fair', 50.0, 30.0),
            ],
        );

        $context = new FormatterContext(namespace: 'App\NonExistent');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Returns null when namespace has no health data (no misleading fallback)
        self::assertNull($data['health']);
    }

    #[Test]
    public function itIncludesShownCountInFindingsMeta(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(55, $data['violationsMeta']['total']);
        self::assertSame(55, $data['violationsMeta']['shown']);
        self::assertNull($data['violationsMeta']['limit']);
        self::assertFalse($data['violationsMeta']['truncated']);
    }

    #[Test]
    public function itShowsShownEqualsTotalInFindingsMeta(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 10; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(10, $data['violationsMeta']['total']);
        self::assertSame(10, $data['violationsMeta']['shown']);
        self::assertFalse($data['violationsMeta']['truncated']);
    }

    #[Test]
    public function itOverridesDefaultWithFormatOptLimit(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 20; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $context = new FormatterContext(options: ['limit' => '5']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(5, $data['violations']);
        self::assertSame(20, $data['violationsMeta']['total']);
        self::assertSame(5, $data['violationsMeta']['shown']);
        self::assertTrue($data['violationsMeta']['truncated']);
    }

    #[Test]
    public function itShowsAllFindingsWhenFormatOptLimitIsZero(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $context = new FormatterContext(options: ['limit' => '0']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(55, $data['violations']);
        self::assertSame(55, $data['violationsMeta']['total']);
        self::assertSame(55, $data['violationsMeta']['shown']);
        self::assertNull($data['violationsMeta']['limit']);
        self::assertFalse($data['violationsMeta']['truncated']);
    }

    #[Test]
    public function itPrioritizesFormatOptViolationsOverLimit(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 20; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();

        // When both are set, findings takes precedence
        $context = new FormatterContext(options: ['violations' => '3', 'limit' => '10']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(3, $data['violations']);
        self::assertSame(3, $data['violationsMeta']['shown']);
    }

    #[Test]
    public function itDoesNotIncludeFindingGroupsWhenGroupByNone(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: 'Test',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(groupBy: GroupBy::None);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('violationGroups', $data);
        self::assertArrayHasKey('violations', $data);
    }

    #[Test]
    public function itIncludesFindingGroupsWhenGroupByClassName(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'process'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Also complex',
                severity: Severity::Warning,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Model/Order.php'), 15),
                symbolPath: SymbolPath::forClass('App\Model', 'Order'),
                ruleName: 'size.class-count',
                code: 'size.class-count',
                message: 'Too large',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(
            groupBy: GroupBy::ClassName,
            isGroupByExplicit: true,
            options: ['violations' => 'all'],
        );
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Flat findings are always present
        self::assertArrayHasKey('violations', $data);
        self::assertCount(3, $data['violations']);

        // Grouped findings are present
        self::assertArrayHasKey('violationGroups', $data);
        self::assertArrayHasKey('App\Service\UserService', $data['violationGroups']);
        self::assertArrayHasKey('App\Model\Order', $data['violationGroups']);

        $userServiceGroup = $data['violationGroups']['App\Service\UserService'];
        self::assertSame(2, $userServiceGroup['count']);
        self::assertCount(2, $userServiceGroup['violations']);

        $orderGroup = $data['violationGroups']['App\Model\Order'];
        self::assertSame(1, $orderGroup['count']);
        self::assertCount(1, $orderGroup['violations']);
    }

    #[Test]
    public function itIncludesFindingGroupsWhenGroupByNamespaceName(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Model/Order.php'), 15),
                symbolPath: SymbolPath::forClass('App\Model', 'Order'),
                ruleName: 'size.class-count',
                code: 'size.class-count',
                message: 'Too large',
                severity: Severity::Warning,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Model/Product.php'), 20),
                symbolPath: SymbolPath::forClass('App\Model', 'Product'),
                ruleName: 'size.class-count',
                code: 'size.class-count',
                message: 'Also large',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(
            groupBy: GroupBy::NamespaceName,
            isGroupByExplicit: true,
            options: ['violations' => 'all'],
        );
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('violationGroups', $data);
        self::assertArrayHasKey('App\Service', $data['violationGroups']);
        self::assertArrayHasKey('App\Model', $data['violationGroups']);

        // App\Model has 2 findings — sorted first (worst first)
        $findingGroups = $data['violationGroups'];
        \assert(\is_array($findingGroups));
        $keys = array_keys($findingGroups);
        self::assertSame('App\Model', $keys[0]);
        self::assertSame('App\Service', $keys[1]);

        self::assertSame(2, $data['violationGroups']['App\Model']['count']);
        self::assertSame(1, $data['violationGroups']['App\Service']['count']);
    }

    #[Test]
    public function itIncludesFindingGroupsWhenGroupByFile(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: 'Test A',
                severity: Severity::Warning,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                code: 'test',
                message: 'Test B',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(
            groupBy: GroupBy::File,
            isGroupByExplicit: true,
            options: ['violations' => 'all'],
        );
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('violationGroups', $data);
        self::assertCount(2, $data['violationGroups']);
    }

    #[Test]
    public function itBuildsFindingGroupsFromTruncatedList(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        // Add 5 findings to ClassA, 3 to ClassB
        for ($i = 0; $i < 5; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/A.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: "Violation A{$i}",
                severity: Severity::Warning,
            ));
        }

        for ($i = 0; $i < 3; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                code: 'test',
                message: "Violation B{$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();

        // Limit to 4 findings — groups should be built from the truncated list
        $context = new FormatterContext(
            groupBy: GroupBy::ClassName,
            isGroupByExplicit: true,
            options: ['violations' => '4'],
        );
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(4, $data['violations']);
        self::assertSame(8, $data['violationsMeta']['total']);
        self::assertTrue($data['violationsMeta']['truncated']);

        // Groups are built from the 4 shown findings
        $totalGrouped = 0;
        foreach ($data['violationGroups'] as $group) {
            $totalGrouped += $group['count'];
        }
        self::assertSame(4, $totalGrouped);
    }

    #[Test]
    public function itReturnsEmptyFindingGroupsWhenNoFindings(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(
            groupBy: GroupBy::ClassName,
            isGroupByExplicit: true,
        );
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('violationGroups', $data);
        self::assertSame([], $data['violationGroups']);
    }

    #[Test]
    public function itSortsFindingGroupsByCountDescending(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        // 1 finding for ClassA
        $builder->addFinding(self::finding(
            location: new Location(RelativePath::fromString('src/A.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'A'),
            ruleName: 'test',
            code: 'test',
            message: 'Test',
            severity: Severity::Warning,
        ));

        // 3 findings for ClassB
        for ($i = 0; $i < 3; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/B.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                code: 'test',
                message: "Test B{$i}",
                severity: Severity::Warning,
            ));
        }

        // 2 findings for ClassC
        for ($i = 0; $i < 2; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/C.php'), $i + 1),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'test',
                code: 'test',
                message: "Test C{$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();
        $context = new FormatterContext(
            groupBy: GroupBy::ClassName,
            isGroupByExplicit: true,
            options: ['violations' => 'all'],
        );
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $keys = array_keys($data['violationGroups']);
        // ClassB (3) first, then ClassC (2), then ClassA (1)
        self::assertSame('App\B', $keys[0]);
        self::assertSame('App\C', $keys[1]);
        self::assertSame('App\A', $keys[2]);
    }

    /**
     * Builds a finding fixture with an explicit declaration or aggregate
     * subject, preserving the production contract without hiding it behind a
     * legacy fallback.
     *
     * @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations
     */
    private static function finding(
        \Qualimetrix\Analysis\Finding\Contract\Location $location,
        \Qualimetrix\Core\Symbol\SymbolPath $symbolPath,
        string $ruleName,
        string $code,
        string $message,
        \Qualimetrix\Analysis\Finding\Contract\Severity $severity,
        int|float|null $metricValue = null,
        array $relatedLocations = [],
        ?string $recommendation = null,
        int|float|null $threshold = null,
        ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null,
        ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null,
        ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null,
        ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null,
        ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null,
    ): Finding {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File,
            \Qualimetrix\Core\Symbol\SymbolType::Namespace_,
            \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            code: $code,
            message: $message,
            severity: $severity,
            metricValue: $metricValue,
            relatedLocations: $relatedLocations,
            recommendation: $recommendation,
            threshold: $threshold,
            dependencyTarget: $dependencyTarget,
            dependencyType: $dependencyType,
            acceptedLevel: $acceptedLevel,
            occurrenceKey: $occurrenceKey,
        );
    }

}
