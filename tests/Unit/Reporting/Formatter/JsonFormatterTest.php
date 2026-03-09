<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Formatter\JsonFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\ReportBuilder;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonFormatter::class)]
final class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new JsonFormatter(new DebtCalculator(new RemediationTimeRegistry()));
    }

    public function testGetNameReturnsJson(): void
    {
        self::assertSame('json', $this->formatter->getName());
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

        self::assertSame('1.0.0', $data['version']);
        self::assertSame('aimd', $data['package']);
        self::assertArrayHasKey('timestamp', $data);
        self::assertSame([], $data['files']);

        self::assertSame(42, $data['summary']['filesAnalyzed']);
        self::assertSame(0, $data['summary']['filesSkipped']);
        self::assertSame(0, $data['summary']['violations']);
        self::assertSame(0, $data['summary']['errors']);
        self::assertSame(0, $data['summary']['warnings']);
        self::assertSame(0.15, $data['summary']['duration']);

        // Debt section
        self::assertArrayHasKey('debt', $data);
        self::assertSame('0min', $data['debt']['total']);
        self::assertSame(0, $data['debt']['totalMinutes']);
        self::assertSame([], $data['debt']['perRule']);
        self::assertSame([], $data['debt']['perFile']);
    }

    public function testFormatReportWithViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 12 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 12,
            ))
            ->filesAnalyzed(42)
            ->filesSkipped(1)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['files']);
        self::assertSame('src/Service/UserService.php', $data['files'][0]['file']);
        self::assertCount(2, $data['files'][0]['violations']);

        // First violation
        $v1 = $data['files'][0]['violations'][0];
        self::assertSame(42, $v1['beginLine']);
        self::assertSame(42, $v1['endLine']);
        self::assertSame('cyclomatic-complexity', $v1['rule']);
        self::assertSame('cyclomatic-complexity', $v1['code']);
        self::assertSame('App\Service\UserService::calculateDiscount', $v1['symbol']);
        self::assertSame(1, $v1['priority']);
        self::assertSame('error', $v1['severity']);
        self::assertSame('Cyclomatic complexity of 25 exceeds threshold', $v1['description']);
        self::assertSame(25, $v1['metricValue']);

        // Second violation
        $v2 = $data['files'][0]['violations'][1];
        self::assertSame(120, $v2['beginLine']);
        self::assertSame('cyclomatic-complexity', $v2['code']);
        self::assertSame(3, $v2['priority']);
        self::assertSame('warning', $v2['severity']);
        self::assertSame(12, $v2['metricValue']);

        // Summary
        self::assertSame(42, $data['summary']['filesAnalyzed']);
        self::assertSame(1, $data['summary']['filesSkipped']);
        self::assertSame(2, $data['summary']['violations']);
        self::assertSame(1, $data['summary']['errors']);
        self::assertSame(1, $data['summary']['warnings']);

        // Debt
        self::assertArrayHasKey('debt', $data);
        self::assertGreaterThan(0, $data['debt']['totalMinutes']);
        self::assertArrayHasKey('cyclomatic-complexity', $data['debt']['perRule']);
        self::assertSame(2, $data['debt']['perRule']['cyclomatic-complexity']['violations']);
        self::assertArrayHasKey('src/Service/UserService.php', $data['debt']['perFile']);
    }

    public function testFormatGroupsViolationsByFile(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Error in A',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Error in B',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/A.php', 30),
                symbolPath: SymbolPath::forClass('App', 'A2'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Second error in A',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['files']);

        // File A has 2 violations
        $fileA = array_values(array_filter(
            $data['files'],
            static fn(array $f): bool => $f['file'] === 'src/A.php',
        ))[0];
        self::assertCount(2, $fileA['violations']);

        // File B has 1 violation
        $fileB = array_values(array_filter(
            $data['files'],
            static fn(array $f): bool => $f['file'] === 'src/B.php',
        ))[0];
        self::assertCount(1, $fileB['violations']);
    }

    public function testFormatNamespaceLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                violationCode: 'namespace-size',
                message: 'Namespace contains 16 classes (threshold: 10)',
                severity: Severity::Error,
                metricValue: 16,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $v = $data['files'][0]['violations'][0];
        self::assertNull($v['beginLine']);
        self::assertSame('App\Service', $v['symbol']);
    }

    public function testFormatFloatMetricValue(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'instability',
                violationCode: 'instability',
                message: 'Instability too high',
                severity: Severity::Warning,
                metricValue: 0.85,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(0.85, $data['files'][0]['violations'][0]['metricValue']);
    }

    public function testTimestampIsIso8601(): void
    {
        $report = new Report([], 0, 0, 0.0, 0, 0);
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Validate ISO 8601 format
        $timestamp = $data['timestamp'];
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
    }

    public function testViolationCodeDiffersFromRuleName(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                violationCode: 'complexity.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $violation = $data['files'][0]['violations'][0];
        self::assertSame('complexity', $violation['rule']);
        self::assertSame('complexity.method', $violation['code']);
    }

    public function testNanMetricValueProducesNullInJson(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'maintainability.index',
                violationCode: 'maintainability.index',
                message: 'Maintainability index is NaN',
                severity: Severity::Warning,
                metricValue: \NAN,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'maintainability.index',
                violationCode: 'maintainability.index',
                message: 'Maintainability index is INF',
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

        $vA = $data['files'][0]['violations'][0];
        self::assertNull($vA['metricValue'], 'NaN metric value should be null in JSON');

        $vB = $data['files'][1]['violations'][0];
        self::assertNull($vB['metricValue'], 'INF metric value should be null in JSON');
    }

    public function testGetDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testRelativizesPathsWithBasePath(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
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

        self::assertSame('src/Service/UserService.php', $data['files'][0]['file']);
    }
}
