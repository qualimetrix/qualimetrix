<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer;
use Qualimetrix\Reporting\Formatter\TextFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(TextFormatter::class)]
final class TextFormatterTest extends TestCase
{
    private TextFormatter $formatter;
    private FormatterContext $plainContext;

    protected function setUp(): void
    {
        $debtCalculator = new DebtCalculator(new RemediationTimeRegistry());
        $this->formatter = new TextFormatter($debtCalculator, new DetailedViolationRenderer($debtCalculator));
        $this->plainContext = new FormatterContext(useColor: false);
    }

    public function testGetNameReturnsText(): void
    {
        self::assertSame('text', $this->formatter->getName());
    }

    public function testGetDefaultGroupByReturnsNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertSame("0 error(s), 0 warning(s) in 42 file(s)\nTechnical debt: 0min\n", $output);
    }

    public function testFormatSingleViolation(): void
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
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        $lines = explode("\n", rtrim($output, "\n"));

        self::assertCount(4, $lines);
        self::assertSame(
            'src/Service/UserService.php: error[cyclomatic-complexity]: Cyclomatic complexity of 25 exceeds threshold (UserService::calculateDiscount)',
            $lines[0],
        );
        self::assertSame('', $lines[1]);
        self::assertSame('1 error(s), 0 warning(s) in 1 file(s)', $lines[2]);
        self::assertStringStartsWith('Technical debt:', $lines[3]);
        self::assertStringEndsWith("\n", $output);
    }

    public function testFormatMultipleViolations(): void
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
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        $lines = explode("\n", rtrim($output, "\n"));

        self::assertCount(5, $lines);
        self::assertStringStartsWith('src/Service/UserService.php: error[cyclomatic-complexity]:', $lines[0]);
        self::assertStringStartsWith('src/Service/UserService.php: warning[cyclomatic-complexity]:', $lines[1]);
        self::assertSame('', $lines[2]);
        self::assertSame('1 error(s), 1 warning(s) in 1 file(s)', $lines[3]);
        self::assertStringStartsWith('Technical debt:', $lines[4]);
        self::assertStringEndsWith("\n", $output);
    }

    public function testFormatClassLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 10),
                symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                ruleName: 'lcom',
                violationCode: 'lcom',
                message: 'LCOM is 5',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.05)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('warning[lcom]: LCOM is 5 (UserService)', $output);
    }

    public function testFormatNamespaceLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                violationCode: 'namespace-size',
                message: 'Namespace contains 16 classes',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('src/Service/UserService.php: error[namespace-size]: Namespace contains 16 classes (namespace: App\Service)', $output);
    }

    public function testFormatFileLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forFile('src/Service/UserService.php'),
                ruleName: 'file-size',
                violationCode: 'file-size',
                message: 'File is too large',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('src/Service/UserService.php: warning[file-size]: File is too large', $output);
    }

    public function testFormatGlobalFunctionViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/functions.php', 5),
                symbolPath: SymbolPath::forGlobalFunction('', 'myComplexFunction'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Function has complexity of 20',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('src/functions.php: warning[cyclomatic-complexity]: Function has complexity of 20 (myComplexFunction)', $output);
    }

    public function testOutputIsParseable(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10, precise: true),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Test message',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);
        $lines = explode("\n", $output);
        $violationLine = $lines[0];

        // Parse using cut-like logic: file:line: severity[rule]: message (symbol)
        if (preg_match('/^([^:]+):(\d+): (error|warning)\[([^\]]+)\]: (.+)$/', $violationLine, $matches) !== 1) {
            self::fail('Violation line does not match expected format: ' . $violationLine);
        }
        self::assertSame('src/Foo.php', $matches[1]);
        self::assertSame('10', $matches[2]);
        self::assertSame('error', $matches[3]);
        self::assertSame('test-rule', $matches[4]);
        self::assertSame('Test message (Foo::bar)', $matches[5]);
    }

    public function testViolationCodeUsedInBrackets(): void
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

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('[complexity.method]', $output);
        self::assertStringNotContainsString('[complexity]', $output);
    }

    public function testColoredOutputContainsAnsiCodes(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Should contain ANSI escape codes
        self::assertStringContainsString("\e[", $output);
        // Error severity should be red
        self::assertStringContainsString("\e[31merror\e[0m", $output);
    }

    public function testNoAnsiCodesWithColorDisabled(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringNotContainsString("\e[", $output);
    }

    public function testSortingBySeverityThenFile(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('b.php', 5),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Warning B',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('a.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Error A',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        // Default groupBy=None sorts by severity first: error before warning
        $posError = strpos($output, 'Error A');
        $posWarning = strpos($output, 'Warning B');

        self::assertNotFalse($posError);
        self::assertNotFalse($posWarning);
        self::assertLessThan($posWarning, $posError);
    }

    public function testSummaryColoredRedForErrors(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('a.php', 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Msg',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Summary should be bold red when errors present
        self::assertStringContainsString("\e[1;31m1 error(s)", $output);
    }

    public function testRelativizesAbsolutePathsWithBasePath(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'calculate'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $context = new FormatterContext(useColor: false, basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('src/Service/UserService.php:', $output);
        self::assertStringNotContainsString('/home/user/project/', $output);
    }

    public function testSummaryColoredGreenForNoViolations(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->filesAnalyzed(5)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Summary should be bold green when no violations
        self::assertStringContainsString("\e[1;32m0 error(s)", $output);
    }

    public function testDetailModeGroupsByFile(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Test msg',
                severity: Severity::Error,
                recommendation: 'Human: test error',
            ))
            ->addViolation(new Violation(
                location: new Location('src/Bar.php', 20),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'test',
                violationCode: 'test.rule',
                message: 'Bar msg',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $detailContext = new FormatterContext(useColor: false, detailLimit: 0);
        $output = $this->formatter->format($report, $detailContext);

        // Groups by file
        self::assertStringContainsString('src/Foo.php (1 violation)', $output);
        self::assertStringContainsString('src/Bar.php (1 violation)', $output);

        // Uses recommendation when available
        self::assertStringContainsString('Human: test error', $output);
        // Falls back to message for the second violation
        self::assertStringContainsString('Bar msg', $output);

        // Shows violation code in brackets
        self::assertStringContainsString('[test.rule]', $output);

        // Shows severity tags
        self::assertStringContainsString('ERROR', $output);
        self::assertStringContainsString('WARN', $output);

        // Has debt breakdown
        self::assertStringContainsString('Technical debt by rule:', $output);

        // Has summary at the end
        self::assertStringContainsString('1 error(s), 1 warning(s) in 2 file(s)', $output);
    }

    public function testDetailModeEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(5)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $detailContext = new FormatterContext(useColor: false, detailLimit: 0);
        $output = $this->formatter->format($report, $detailContext);

        self::assertStringContainsString('No violations found.', $output);
        self::assertStringContainsString('0 error(s), 0 warning(s) in 5 file(s)', $output);
    }

    public function testDetailModeRespectsExplicitGroupByRule(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $detailContext = new FormatterContext(
            useColor: false,
            groupBy: GroupBy::Rule,
            detailLimit: 0,
            isGroupByExplicit: true,
        );
        $output = $this->formatter->format($report, $detailContext);

        // Should group by rule, not file
        self::assertStringContainsString('complexity.cyclomatic (1)', $output);
        self::assertStringNotContainsString('src/Foo.php (1', $output);
    }

    public function testDebtBreakdownIncludesAllRulesWhenDetailLimitTruncates(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.01);

        // Add 2 violations of rule A (will be displayed within limit)
        for ($i = 1; $i <= 2; $i++) {
            $builder->addViolation(new Violation(
                location: new Location("src/Foo{$i}.php", 10),
                symbolPath: SymbolPath::forClass('App', "Foo{$i}"),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complex',
                severity: Severity::Error,
            ));
        }

        // Add 1 violation of rule B (may be beyond detailLimit)
        $builder->addViolation(new Violation(
            location: new Location('src/Bar.php', 5),
            symbolPath: SymbolPath::forClass('App', 'Bar'),
            ruleName: 'design.lcom',
            violationCode: 'design.lcom',
            message: 'LCOM high',
            severity: Severity::Warning,
        ));

        $report = $builder->build();

        // Limit to 1 displayed violation, but debt breakdown must still show all rules
        $context = new FormatterContext(useColor: false, detailLimit: 1);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('Technical debt by rule:', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
        self::assertStringContainsString('design.lcom', $output);
        self::assertStringContainsString('... and 2 more', $output);
    }
}
