<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\GithubActionsFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GithubActionsFormatter::class)]
final class GithubActionsFormatterTest extends TestCase
{
    private GithubActionsFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GithubActionsFormatter();
    }

    public function testGetNameReturnsGithub(): void
    {
        self::assertSame('github', $this->formatter->getName());
    }

    public function testGetDefaultGroupByReturnsNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatEmptyReportReturnsEmptyString(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertSame('', $output);
    }

    public function testFormatWarningViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity 15 exceeds warning threshold 10',
                severity: Severity::Warning,
                metricValue: 15,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertSame(
            "::warning file=src/Service/UserService.php,line=42,title=complexity.cyclomatic::Cyclomatic complexity 15 exceeds warning threshold 10\n",
            $output,
        );
    }

    public function testFormatErrorViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity 25 exceeds error threshold 20',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertSame(
            "::error file=src/Service/UserService.php,line=42,title=complexity.cyclomatic::Cyclomatic complexity 25 exceeds error threshold 20\n",
            $output,
        );
    }

    public function testFormatMultipleViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'size.class-count',
                violationCode: 'size.class-count',
                message: 'Too many classes',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        $lines = explode("\n", trim($output));
        self::assertCount(2, $lines);
        self::assertStringStartsWith('::error ', $lines[0]);
        self::assertStringStartsWith('::warning ', $lines[1]);
    }

    public function testFormatEscapesSpecialCharactersInMessage(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Test.php', 1),
                symbolPath: SymbolPath::forClass('App', 'Test'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: "100% coverage\ris\nnot enough",
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('100%25 coverage%0Dis%0Anot enough', $output);
        self::assertStringNotContainsString("\n" . 'not enough', $output);
    }

    public function testFormatIncludesFilePathInOutput(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/OrderService.php', 55),
                symbolPath: SymbolPath::forMethod('App\Service', 'OrderService', 'process'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test message',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('file=src/Service/OrderService.php', $output);
    }

    public function testFormatIncludesLineNumberInOutput(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Test.php', 99),
                symbolPath: SymbolPath::forClass('App', 'Test'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test message',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('line=99', $output);
    }

    public function testFormatUsesViolationCodeAsTitle(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Test.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Test'),
                ruleName: 'complexity',
                violationCode: 'complexity.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('title=complexity.method', $output);
    }

    public function testFormatRelativizesPathsWithBasePath(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('file=src/Service/UserService.php', $output);
        self::assertStringNotContainsString('/home/user/project', $output);
    }

    public function testFormatViolationWithoutLine(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                violationCode: 'size.namespace',
                message: 'Namespace too large',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('file=src/Service/UserService.php', $output);
        self::assertStringNotContainsString('line=', $output);
    }

    public function testFormatEscapesSpecialCharactersInPropertyValues(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/path:with,special/File.php', 1),
                symbolPath: SymbolPath::forClass('App', 'Test'),
                ruleName: 'test-rule',
                violationCode: 'test:rule,name',
                message: 'Test message',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('file=src/path%3Awith%2Cspecial/File.php', $output);
        self::assertStringContainsString('title=test%3Arule%2Cname', $output);
    }

    public function testFormatArchitecturalViolationWithoutFile(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'architecture.circular',
                violationCode: 'architecture.circular',
                message: 'Circular dependency detected',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('::error title=architecture.circular::Circular dependency detected', $output);
        self::assertStringNotContainsString('file=', $output);
    }
}
