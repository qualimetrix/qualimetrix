<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\GitLabCodeQualityFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitLabCodeQualityFormatter::class)]
final class GitLabCodeQualityFormatterTest extends TestCase
{
    private GitLabCodeQualityFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GitLabCodeQualityFormatter();
    }

    public function testGetNameReturnsGitlab(): void
    {
        self::assertSame('gitlab', $this->formatter->getName());
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

        // Empty report should return empty array
        self::assertIsArray($data);
        self::assertSame([], $data);
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

        // Should have 2 issues
        self::assertCount(2, $data);

        // First issue
        $issue1 = $data[0];
        self::assertSame('Cyclomatic complexity of 25 exceeds threshold', $issue1['description']);
        self::assertSame('cyclomatic-complexity', $issue1['check_name']);
        self::assertSame('critical', $issue1['severity']);
        self::assertSame('src/Service/UserService.php', $issue1['location']['path']);
        self::assertSame(42, $issue1['location']['lines']['begin']);
        self::assertArrayHasKey('fingerprint', $issue1);

        // Second issue
        $issue2 = $data[1];
        self::assertSame('Cyclomatic complexity of 12 exceeds threshold', $issue2['description']);
        self::assertSame('cyclomatic-complexity', $issue2['check_name']);
        self::assertSame('major', $issue2['severity']);
        self::assertSame('src/Service/UserService.php', $issue2['location']['path']);
        self::assertSame(120, $issue2['location']['lines']['begin']);
        self::assertArrayHasKey('fingerprint', $issue2);
    }

    public function testMapsSeverityCorrectly(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Error violation',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Warning violation',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Verify GitLab severity mapping
        self::assertSame('critical', $data[0]['severity']);
        self::assertSame('major', $data[1]['severity']);
    }

    public function testGeneratesStableFingerprint(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php', 42),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: 'Cyclomatic complexity of 25 exceeds threshold',
            severity: Severity::Error,
            metricValue: 25,
        );

        $report = ReportBuilder::create()
            ->addViolation($violation)
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        // Format twice
        $output1 = $this->formatter->format($report, new FormatterContext());
        $output2 = $this->formatter->format($report, new FormatterContext());

        $data1 = json_decode($output1, true, 512, \JSON_THROW_ON_ERROR);
        $data2 = json_decode($output2, true, 512, \JSON_THROW_ON_ERROR);

        // Fingerprint should be identical
        self::assertSame($data1[0]['fingerprint'], $data2[0]['fingerprint']);

        // Verify fingerprint format (MD5 hash = 32 hex characters)
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $data1[0]['fingerprint']);
    }

    public function testDifferentViolationsHaveDifferentFingerprints(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'First violation',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Second violation',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'other-rule',
                violationCode: 'other-rule',
                message: 'Same location, different rule',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // All fingerprints should be unique
        $fingerprints = array_map(fn(array $issue): string => $issue['fingerprint'], $data);
        $uniqueFingerprints = array_unique($fingerprints);

        self::assertCount(3, $fingerprints);
        self::assertCount(3, $uniqueFingerprints);
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

        $issue = $data[0];
        // Namespace violations without line should default to line 1
        self::assertSame(1, $issue['location']['lines']['begin']);
    }

    public function testFormatStructureMatchesGitLabSpec(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 45),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'foo'),
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Method foo() has complexity 25, exceeds 10',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $issue = $data[0];

        // Verify all required GitLab Code Quality fields are present
        self::assertArrayHasKey('description', $issue);
        self::assertArrayHasKey('check_name', $issue);
        self::assertArrayHasKey('fingerprint', $issue);
        self::assertArrayHasKey('severity', $issue);
        self::assertArrayHasKey('location', $issue);

        // Verify location structure
        self::assertArrayHasKey('path', $issue['location']);
        self::assertArrayHasKey('lines', $issue['location']);
        self::assertArrayHasKey('begin', $issue['location']['lines']);

        // Verify no extra fields in location (GitLab spec only requires path and lines.begin)
        self::assertCount(2, $issue['location']);
        self::assertCount(1, $issue['location']['lines']);
    }

    public function testCheckNameUsesViolationCode(): void
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

        $issue = $data[0];
        self::assertSame('complexity.method', $issue['check_name']);
    }

    public function testGetDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }
}
