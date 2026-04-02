<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Json\JsonFormatter;
use Qualimetrix\Reporting\Formatter\Json\JsonHealthSection;
use Qualimetrix\Reporting\Formatter\Json\JsonOffenderSection;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\Formatter\Json\JsonViolationSection;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Health\WorstOffender;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(JsonFormatter::class)]
final class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        $hintProvider = new MetricHintProvider();
        $namespaceDrillDown = new NamespaceDrillDown($hintProvider);
        $sanitizer = new JsonSanitizer();
        $violationFilter = new ViolationFilter();
        $remediationTimeRegistry = new RemediationTimeRegistry();
        $this->formatter = new JsonFormatter(
            new DebtCalculator($remediationTimeRegistry),
            $violationFilter,
            new JsonHealthSection(new HealthScoreResolver($namespaceDrillDown), $sanitizer),
            new JsonOffenderSection($namespaceDrillDown, $violationFilter, $sanitizer),
            new JsonViolationSection($remediationTimeRegistry, $sanitizer),
        );
    }

    public function testGetNameReturnsJson(): void
    {
        self::assertSame('json', $this->formatter->getName());
    }

    public function testGetDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatReturnsValidJson(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertJson($output);
    }

    public function testFormatEmptyReport(): void
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

    public function testTimestampIsIso8601(): void
    {
        $report = new Report([], 0, 0, 0.0, 0, 0);
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $timestamp = $data['meta']['timestamp'];
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
    }

    public function testFormatReportWithViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Cyclomatic complexity of 25 exceeds threshold of 10',
                severity: Severity::Error,
                metricValue: 25,
                threshold: 10,
                recommendation: 'Cyclomatic complexity: 25 (threshold: 10) — too many code paths',
            ))
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
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

        // Violations are flat list, sorted by severity (error first)
        self::assertCount(2, $data['violations']);

        $v1 = $data['violations'][0];
        self::assertSame('src/Service/UserService.php', $v1['file']);
        self::assertSame(42, $v1['line']);
        self::assertSame('App\Service\UserService::calculateDiscount', $v1['symbol']);
        self::assertSame('App\Service', $v1['namespace']);
        self::assertSame('complexity.cyclomatic', $v1['rule']);
        self::assertSame('complexity.cyclomatic.method', $v1['code']);
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

    public function testViolationUsesDisplayMessageFallback(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Technical message only',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // message field always uses the raw violation message
        self::assertSame('Technical message only', $data['violations'][0]['message']);
    }

    public function testHealthScoresIncluded(): void
    {
        $report = new Report(
            violations: [],
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

    public function testScopedReportingStillShowsHealth(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 8,
            filesSkipped: 0,
            duration: 0.4,
            errorCount: 0,
            warningCount: 0,
            healthScores: [
                'complexity' => new HealthScore('complexity', 65.0, 'Fair', 50.0, 25.0),
            ],
        );

        $context = new FormatterContext(scopedReporting: true, scopeFilePaths: ['/src/Foo.php']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Health is always shown now — full graph is always available
        self::assertNotNull($data['health']);
        self::assertArrayHasKey('complexity', $data['health']);
    }

    public function testWorstNamespaces(): void
    {
        $report = new Report(
            violations: [],
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
                    violationCount: 12,
                    classCount: 4,
                    metrics: ['cbo.avg' => 8.5],
                    healthScores: ['complexity' => 28.0, 'cohesion' => 25.0],
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

    public function testWorstClasses(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [
                new WorstOffender(
                    symbolPath: SymbolPath::forClass('App\Payment', 'PaymentService'),
                    file: 'src/Payment/PaymentService.php',
                    healthOverall: 28.0,
                    label: 'Critical',
                    reason: '32 methods, high coupling',
                    violationCount: 5,
                    classCount: 0,
                    metrics: ['methodCount' => 32, 'cbo' => 18],
                    healthScores: ['complexity' => 12.0, 'cohesion' => 8.0],
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

    public function testAllViolationsIncludedByDefault(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testDetailImpliesAllViolations(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testFormatOptViolationsOverridesDetail(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 20; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testFormatOptViolationsZero(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testFormatOptViolationsAll(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testNamespaceFilter(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Payment/Pay.php', 10),
                symbolPath: SymbolPath::forClass('App\Payment', 'PayService'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'In Payment',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Payment/Gateway/Stripe.php', 20),
                symbolPath: SymbolPath::forClass('App\Payment\Gateway', 'Stripe'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'In Payment Gateway',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/User/User.php', 30),
                symbolPath: SymbolPath::forClass('App\User', 'UserService'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'In User',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(namespace: 'App\Payment');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Only Payment violations (boundary-aware: App\Payment and App\Payment\Gateway)
        self::assertCount(2, $data['violations']);
        self::assertSame('In Payment', $data['violations'][0]['message']);
        self::assertSame('In Payment Gateway', $data['violations'][1]['message']);

        // Summary reflects filtered set
        self::assertSame(2, $data['summary']['violationCount']);
        self::assertSame(1, $data['summary']['errorCount']);
        self::assertSame(1, $data['summary']['warningCount']);
        // filesAnalyzed stays global
        self::assertSame(3, $data['summary']['filesAnalyzed']);
        // techDebtMinutes recalculated for filtered violations (2 × 15min default)
        self::assertSame(30, $data['summary']['techDebtMinutes']);
    }

    public function testNamespaceFilterBoundaryAware(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/PaymentGateway.php', 10),
                symbolPath: SymbolPath::forClass('App\PaymentGateway', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Not matched',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(namespace: 'App\Payment');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // App\PaymentGateway should NOT match App\Payment
        self::assertSame([], $data['violations']);
    }

    public function testClassFilter(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Payment/Pay.php', 10),
                symbolPath: SymbolPath::forMethod('App\Payment', 'PayService', 'process'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'In PayService',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/User/User.php', 30),
                symbolPath: SymbolPath::forClass('App\User', 'UserService'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'In UserService',
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

    public function testNanAndInfMetricValuesProduceNull(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'maintainability.index',
                violationCode: 'maintainability.index',
                message: 'MI is NaN',
                severity: Severity::Warning,
                metricValue: \NAN,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'maintainability.index',
                violationCode: 'maintainability.index',
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

    public function testViolationsSortedBySeverityThenExceedance(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test.a',
                message: 'Warning low exceedance',
                severity: Severity::Warning,
                metricValue: 11,
                threshold: 10,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test.b',
                message: 'Error low exceedance',
                severity: Severity::Error,
                metricValue: 11,
                threshold: 10,
            ))
            ->addViolation(new Violation(
                location: new Location('src/C.php', 30),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'test',
                violationCode: 'test.c',
                message: 'Warning high exceedance',
                severity: Severity::Warning,
                metricValue: 50,
                threshold: 10,
            ))
            ->addViolation(new Violation(
                location: new Location('src/D.php', 40),
                symbolPath: SymbolPath::forClass('App', 'D'),
                ruleName: 'test',
                violationCode: 'test.d',
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

        // Errors first, higher exceedance first within same severity
        self::assertSame('test.d', $data['violations'][0]['code']); // error, exceedance=40
        self::assertSame('test.b', $data['violations'][1]['code']); // error, exceedance=1
        self::assertSame('test.c', $data['violations'][2]['code']); // warning, exceedance=40
        self::assertSame('test.a', $data['violations'][3]['code']); // warning, exceedance=1
    }

    public function testNullThresholdViolationsStillSorted(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'code-smell',
                violationCode: 'code-smell.eval',
                message: 'No threshold',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

        // Threshold-based exceedance (10) comes first, then null threshold (0)
        self::assertSame('test', $data['violations'][0]['code']);
        self::assertSame('code-smell.eval', $data['violations'][1]['code']);
    }

    public function testRelativizesPathsWithBasePath(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('src/Service/UserService.php', $data['violations'][0]['file']);
    }

    public function testNamespaceLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'size.namespace',
                violationCode: 'size.namespace',
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

    public function testFilelessViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App'),
                ruleName: 'architecture.circular',
                violationCode: 'architecture.circular',
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

    public function testFormatOptTopN(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: [
                new WorstOffender(SymbolPath::forNamespace('App\A'), null, 20.0, 'Critical', 'bad', 5, 3),
                new WorstOffender(SymbolPath::forNamespace('App\B'), null, 25.0, 'Critical', 'bad', 3, 2),
                new WorstOffender(SymbolPath::forNamespace('App\C'), null, 30.0, 'Critical', 'bad', 2, 1),
            ],
        );

        $context = new FormatterContext(options: ['top' => '2']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['worstNamespaces']);
    }

    public function testNanInHealthScoresSanitized(): void
    {
        $report = new Report(
            violations: [],
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

    public function testEmptyHealthScoresProducesNull(): void
    {
        $report = new Report([], 10, 0, 0.5, 0, 0);

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertNull($data['health']);
    }

    public function testWorstOffendersFilteredByNamespace(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 100,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: [
                new WorstOffender(SymbolPath::forNamespace('App\Payment'), null, 30.0, 'Critical', 'bad', 5, 3),
                new WorstOffender(SymbolPath::forNamespace('App\Payment\Gateway'), null, 25.0, 'Critical', 'bad', 3, 2),
                new WorstOffender(SymbolPath::forNamespace('App\User'), null, 35.0, 'Critical', 'bad', 2, 1),
            ],
        );

        $context = new FormatterContext(namespace: 'App\Payment');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['worstNamespaces']);
        self::assertSame('App\Payment', $data['worstNamespaces'][0]['symbolPath']);
        self::assertSame('App\Payment\Gateway', $data['worstNamespaces'][1]['symbolPath']);
    }

    public function testInvalidViolationsOptionFallsBackToDefault(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testWorstClassFileRelativized(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [
                new WorstOffender(
                    symbolPath: SymbolPath::forClass('App', 'Foo'),
                    file: '/home/user/project/src/Foo.php',
                    healthOverall: 20.0,
                    label: 'Critical',
                    reason: 'bad',
                    violationCount: 1,
                    classCount: 0,
                ),
            ],
        );

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('src/Foo.php', $data['worstClasses'][0]['file']);
    }

    public function testScopedReportingShowsOffenders(): void
    {
        $report = new Report(
            violations: [],
            filesAnalyzed: 8,
            filesSkipped: 0,
            duration: 0.4,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: [
                new WorstOffender(SymbolPath::forNamespace('App'), null, 30.0, 'Critical', 'bad', 5, 3),
            ],
            worstClasses: [
                new WorstOffender(SymbolPath::forClass('App', 'Foo'), 'src/Foo.php', 20.0, 'Critical', 'bad', 1, 0),
            ],
        );

        $context = new FormatterContext(scopedReporting: true, scopeFilePaths: ['/src/Foo.php']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Scoped reporting: offenders and health are always shown (full graph available)
        self::assertNotEmpty($data['worstNamespaces']);
        self::assertNotEmpty($data['worstClasses']);
    }

    public function testNanMetricValueSortedCorrectly(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test.a',
                message: 'NaN metric',
                severity: Severity::Warning,
                metricValue: \NAN,
                threshold: 10,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test.b',
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

        // Normal metric (exceedance=15) should come before NaN (exceedance=0)
        self::assertCount(2, $data['violations']);
        self::assertSame('test.b', $data['violations'][0]['code']);
        self::assertSame('test.a', $data['violations'][1]['code']);
    }

    public function testNamespaceFilterShowsNamespaceHealthScores(): void
    {
        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \Qualimetrix\Core\Metric\MetricBag::fromArray([
            'health.overall' => 40.0,
            'health.complexity' => 55.0,
            'health.cohesion' => 25.0,
            'classCount' => 5,
        ]);

        $metrics = $this->createMock(\Qualimetrix\Core\Metric\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturnCallback(
            static fn(SymbolPath $sp): bool => $sp->toCanonical() === $nsPath->toCanonical(),
        );
        $metrics->method('get')->willReturnCallback(
            static fn(SymbolPath $sp): \Qualimetrix\Core\Metric\MetricBag => $sp->toCanonical() === $nsPath->toCanonical()
                ? $nsMetrics
                : new \Qualimetrix\Core\Metric\MetricBag(),
        );
        $metrics->method('all')->willReturnCallback(
            static fn(\Qualimetrix\Core\Symbol\SymbolType $type): array => $type === \Qualimetrix\Core\Symbol\SymbolType::Namespace_
                ? [new \Qualimetrix\Core\Symbol\SymbolInfo($nsPath, 'src/Service', 0)]
                : [],
        );

        $report = new Report(
            violations: [],
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

    public function testNamespaceFilterBuildsWorstClassesFromMetrics(): void
    {
        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classMetrics = \Qualimetrix\Core\Metric\MetricBag::fromArray([
            'health.overall' => 25.0,
            'health.complexity' => 20.0,
            'health.cohesion' => 15.0,
            'methodCount' => 32,
            'cbo' => 18,
        ]);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \Qualimetrix\Core\Metric\MetricBag::fromArray([
            'health.overall' => 40.0,
        ]);

        $metrics = $this->createMock(\Qualimetrix\Core\Metric\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturnCallback(
            static fn(SymbolPath $sp): bool => $sp->toCanonical() === $nsPath->toCanonical(),
        );
        $metrics->method('get')->willReturnCallback(
            static fn(SymbolPath $sp): \Qualimetrix\Core\Metric\MetricBag => match ($sp->toCanonical()) {
                $nsPath->toCanonical() => $nsMetrics,
                $classPath->toCanonical() => $classMetrics,
                default => new \Qualimetrix\Core\Metric\MetricBag(),
            },
        );
        $metrics->method('all')->willReturnCallback(
            static fn(\Qualimetrix\Core\Symbol\SymbolType $type): array => $type === \Qualimetrix\Core\Symbol\SymbolType::Class_
                ? [new \Qualimetrix\Core\Symbol\SymbolInfo($classPath, 'src/Service/UserService.php', 1)]
                : [],
        );

        $report = new Report(
            violations: [],
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

    public function testNamespaceFilterFallsBackToProjectWhenNoNsMetrics(): void
    {
        $metrics = $this->createMock(\Qualimetrix\Core\Metric\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturn(false);
        $metrics->method('get')->willReturn(new \Qualimetrix\Core\Metric\MetricBag());
        $metrics->method('all')->willReturn([]);

        $report = new Report(
            violations: [],
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

    public function testViolationsMetaIncludesShownCount(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testViolationsMetaShownEqualsTotal(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 10; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testFormatOptLimitOverridesDefault(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 20; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testFormatOptLimitZeroShowsAllViolations(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 55; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testFormatOptViolationsTakesPrecedenceOverLimit(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        for ($i = 0; $i < 20; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: "Violation {$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();

        // When both are set, violations takes precedence
        $context = new FormatterContext(options: ['violations' => '3', 'limit' => '10']);
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(3, $data['violations']);
        self::assertSame(3, $data['violationsMeta']['shown']);
    }

    public function testGroupByNoneDoesNotIncludeViolationGroups(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testGroupByClassNameIncludesViolationGroups(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'process'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Also complex',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Model/Order.php', 15),
                symbolPath: SymbolPath::forClass('App\Model', 'Order'),
                ruleName: 'size.class-count',
                violationCode: 'size.class-count',
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

        // Flat violations are always present
        self::assertArrayHasKey('violations', $data);
        self::assertCount(3, $data['violations']);

        // Grouped violations are present
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

    public function testGroupByNamespaceNameIncludesViolationGroups(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Model/Order.php', 15),
                symbolPath: SymbolPath::forClass('App\Model', 'Order'),
                ruleName: 'size.class-count',
                violationCode: 'size.class-count',
                message: 'Too large',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Model/Product.php', 20),
                symbolPath: SymbolPath::forClass('App\Model', 'Product'),
                ruleName: 'size.class-count',
                violationCode: 'size.class-count',
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

        // App\Model has 2 violations — sorted first (worst first)
        $keys = array_keys($data['violationGroups']);
        self::assertSame('App\Model', $keys[0]);
        self::assertSame('App\Service', $keys[1]);

        self::assertSame(2, $data['violationGroups']['App\Model']['count']);
        self::assertSame(1, $data['violationGroups']['App\Service']['count']);
    }

    public function testGroupByFileIncludesViolationGroups(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test A',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
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

    public function testViolationGroupsBuiltFromTruncatedList(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        // Add 5 violations to ClassA, 3 to ClassB
        for ($i = 0; $i < 5; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/A.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: "Violation A{$i}",
                severity: Severity::Warning,
            ));
        }

        for ($i = 0; $i < 3; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/B.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: "Violation B{$i}",
                severity: Severity::Warning,
            ));
        }

        $report = $builder->build();

        // Limit to 4 violations — groups should be built from the truncated list
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

        // Groups are built from the 4 shown violations
        $totalGrouped = 0;
        foreach ($data['violationGroups'] as $group) {
            $totalGrouped += $group['count'];
        }
        self::assertSame(4, $totalGrouped);
    }

    public function testViolationGroupsEmptyWhenNoViolations(): void
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

    public function testViolationGroupsSortedByCountDescending(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1);

        // 1 violation for ClassA
        $builder->addViolation(new Violation(
            location: new Location('src/A.php', 1),
            symbolPath: SymbolPath::forClass('App', 'A'),
            ruleName: 'test',
            violationCode: 'test',
            message: 'Test',
            severity: Severity::Warning,
        ));

        // 3 violations for ClassB
        for ($i = 0; $i < 3; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/B.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: "Test B{$i}",
                severity: Severity::Warning,
            ));
        }

        // 2 violations for ClassC
        for ($i = 0; $i < 2; $i++) {
            $builder->addViolation(new Violation(
                location: new Location('src/C.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'test',
                violationCode: 'test',
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
}
