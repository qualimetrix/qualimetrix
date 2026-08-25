<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Support\DetailedFindingRenderer;
use Qualimetrix\Reporting\Formatter\TextFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(TextFormatter::class)]
final class TextFormatterTest extends TestCase
{
    private TextFormatter $formatter;
    private FormatterContext $plainContext;

    protected function setUp(): void
    {
        $debtCalculator = new DebtCalculator(new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues()));
        $this->formatter = new TextFormatter($debtCalculator, new DetailedFindingRenderer($debtCalculator));
        $this->plainContext = new FormatterContext(useColor: false);
    }

    #[Test]
    public function itReturnsTextName(): void
    {
        self::assertSame('text', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsDefaultGroupByNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itFormatsEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('0 error(s), 0 warning(s) in 42 file(s)', $output);
        self::assertStringContainsString('Qualimetrix ', $output);
        self::assertStringContainsString("Technical debt: 0min\n", $output);
    }

    #[Test]
    public function itFormatsSingleFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                code: 'cyclomatic-complexity',
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
        self::assertStringContainsString('1 error(s), 0 warning(s) in 1 file(s)', $lines[2]);
        self::assertStringStartsWith('Technical debt:', $lines[3]);
        self::assertStringEndsWith("\n", $output);
    }

    #[Test]
    public function itNamesTheAcceptedLevelOnABreach(): void
    {
        $report = ReportBuilder::create()
            ->addFinding((self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity of 31 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 31,
            ))->reportedAsBreach(new AcceptedLevel([25.0], 1)))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString(
            'Cyclomatic complexity of 31 exceeds threshold (accepted at 25, now 31) (UserService::calculateDiscount)',
            $output,
        );
    }

    #[Test]
    public function itOmitsTheAcceptedLevelFragmentWhenAbsent(): void
    {
        // Regression pin: a finding with no acceptedLevel must produce
        // byte-for-byte the same line as before this feature existed.
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                code: 'cyclomatic-complexity',
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

        self::assertSame(
            'src/Service/UserService.php: error[cyclomatic-complexity]: Cyclomatic complexity of 25 exceeds threshold (UserService::calculateDiscount)',
            $lines[0],
        );
        self::assertStringNotContainsString('accepted at', $output);
    }

    #[Test]
    public function itFormatsMultipleFindings(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                code: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'cyclomatic-complexity',
                code: 'cyclomatic-complexity',
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
        self::assertStringContainsString('1 error(s), 1 warning(s) in 1 file(s)', $lines[3]);
        self::assertStringStartsWith('Technical debt:', $lines[4]);
        self::assertStringEndsWith("\n", $output);
    }

    #[Test]
    public function itFormatsClassLevelFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 10),
                symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                ruleName: 'lcom',
                code: 'lcom',
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

    #[Test]
    public function itFormatsNamespaceLevelFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php')),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                code: 'namespace-size',
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

    #[Test]
    public function itFormatsFileLevelFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Service/UserService.php')),
                symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Service/UserService.php')),
                ruleName: 'file-size',
                code: 'file-size',
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

    #[Test]
    public function itFormatsGlobalFunctionFinding(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/functions.php'), 5),
                symbolPath: SymbolPath::forGlobalFunction('', 'myComplexFunction'),
                ruleName: 'cyclomatic-complexity',
                code: 'cyclomatic-complexity',
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

    #[Test]
    public function itProducesParseableOutput(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10, precise: true),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test-rule',
                code: 'test-rule',
                message: 'Test message',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);
        $lines = explode("\n", $output);
        $findingLine = $lines[0];

        // Parse using cut-like logic: file:line: severity[rule]: message (symbol)
        if (preg_match('/^([^:]+):(\d+): (error|warning)\[([^\]]+)\]: (.+)$/', $findingLine, $matches) !== 1) {
            self::fail('Violation line does not match expected format: ' . $findingLine);
        }
        self::assertSame('src/Foo.php', $matches[1]);
        self::assertSame('10', $matches[2]);
        self::assertSame('error', $matches[3]);
        self::assertSame('test-rule', $matches[4]);
        self::assertSame('Test message (Foo::bar)', $matches[5]);
    }

    #[Test]
    public function itUsesCodeInBrackets(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                code: 'complexity.callable',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('[complexity.callable]', $output);
        self::assertStringNotContainsString('[complexity]', $output);
    }

    #[Test]
    public function itProducesColoredOutputWithAnsiCodes(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test',
                code: 'test',
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

    #[Test]
    public function itProducesNoAnsiCodesWithColorDisabled(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'test',
                code: 'test',
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

    #[Test]
    public function itSortsBySeverityThenFile(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('b.php'), 5),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                code: 'test',
                message: 'Warning B',
                severity: Severity::Warning,
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('a.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
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

    #[Test]
    public function itColorsSummaryRedForErrors(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('a.php'), 1),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                code: 'test',
                message: 'Msg',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Summary should be bold red when errors present
        self::assertStringContainsString("\e[1;31mQualimetrix ", $output);
        self::assertStringContainsString('1 error(s)', $output);
    }

    #[Test]
    public function itColorsSummaryGreenForNoFindings(): void
    {
        $colorContext = new FormatterContext(useColor: true);

        $report = ReportBuilder::create()
            ->filesAnalyzed(5)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, $colorContext);

        // Summary should be bold green when no findings
        self::assertStringContainsString("\e[1;32mQualimetrix ", $output);
        self::assertStringContainsString('0 error(s)', $output);
    }

    #[Test]
    public function itGroupsByFileInDetailMode(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                code: 'test.rule',
                message: 'Test msg',
                severity: Severity::Error,
                recommendation: 'Human: test error',
            ))
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Bar.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'Bar'),
                ruleName: 'test',
                code: 'test.rule',
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
        // Falls back to message for the second finding
        self::assertStringContainsString('Bar msg', $output);

        // Shows finding code in brackets
        self::assertStringContainsString('[test.rule]', $output);

        // Shows severity tags
        self::assertStringContainsString('ERROR', $output);
        self::assertStringContainsString('WARN', $output);

        // Has debt breakdown
        self::assertStringContainsString('Technical debt by rule:', $output);

        // Has summary at the end
        self::assertStringContainsString('1 error(s), 1 warning(s) in 2 file(s)', $output);
    }

    #[Test]
    public function itHandlesEmptyReportInDetailMode(): void
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

    #[Test]
    public function itRespectsExplicitGroupByRuleInDetailMode(): void
    {
        $report = ReportBuilder::create()
            ->addFinding(self::finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
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

    #[Test]
    public function itIncludesAllRulesInDebtBreakdownWhenDetailLimitTruncates(): void
    {
        $builder = ReportBuilder::create()
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.01);

        // Add 2 findings of rule A (will be displayed within limit)
        for ($i = 1; $i <= 2; $i++) {
            $builder->addFinding(self::finding(
                location: new Location(RelativePath::fromString("src/Foo{$i}.php"), 10),
                symbolPath: SymbolPath::forClass('App', "Foo{$i}"),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Complex',
                severity: Severity::Error,
            ));
        }

        // Add 1 finding of rule B (may be beyond detailLimit)
        $builder->addFinding(self::finding(
            location: new Location(RelativePath::fromString('src/Bar.php'), 5),
            symbolPath: SymbolPath::forClass('App', 'Bar'),
            ruleName: 'cohesion.lcom',
            code: 'cohesion.lcom',
            message: 'LCOM high',
            severity: Severity::Warning,
        ));

        $report = $builder->build();

        // Limit to 1 displayed finding, but debt breakdown must still show all rules
        $context = new FormatterContext(useColor: false, detailLimit: 1);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('Technical debt by rule:', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
        self::assertStringContainsString('cohesion.lcom', $output);
        self::assertStringContainsString('... and 2 more', $output);
    }

    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations */
    private static function finding(\Qualimetrix\Analysis\Finding\Contract\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $code, string $message, \Qualimetrix\Analysis\Finding\Contract\Severity $severity, int|float|null $metricValue = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null, ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Finding
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };
        return new Finding(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, code: $code, message: $message, severity: $severity, metricValue: $metricValue, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}
