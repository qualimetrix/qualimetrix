<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\GithubActionsFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(GithubActionsFormatter::class)]
final class GithubActionsFormatterTest extends TestCase
{
    private GithubActionsFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GithubActionsFormatter();
    }

    #[Test]
    public function itReturnsGithubAsName(): void
    {
        self::assertSame('github', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsNoneAsDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itReturnsEmptyStringForEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertSame('', $output);
    }

    #[Test]
    public function itFormatsWarningViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
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

    #[Test]
    public function itFormatsErrorViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
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

    #[Test]
    public function itUsesNoticeCommandForInfoViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 7),
                symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                ruleName: 'architecture.coverage',
                violationCode: 'architecture.coverage',
                message: 'Class not assigned to a layer',
                severity: Severity::Info,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringStartsWith('::notice ', $output);
    }

    #[Test]
    public function itFormatsMultipleViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
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

    #[Test]
    public function itEscapesSpecialCharactersInMessage(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Test.php'), 1),
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

    #[Test]
    public function itIncludesFilePathInOutput(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/OrderService.php'), 55),
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

    #[Test]
    public function itIncludesLineNumberInOutput(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Test.php'), 99),
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

    #[Test]
    public function itUsesViolationCodeAsTitle(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Test.php'), 10),
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

    #[Test]
    public function itFormatsViolationWithoutLine(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php')),
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

    #[Test]
    public function itEscapesSpecialCharactersInPropertyValues(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/path:with,special/File.php'), 1),
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

    #[Test]
    public function itFormatsArchitecturalViolationWithoutFile(): void
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
