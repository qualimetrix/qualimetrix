<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Filter\ViolationFilter;
use AiMessDetector\Reporting\Formatter\Summary\HealthBarRenderer;
use AiMessDetector\Reporting\Formatter\Summary\HintRenderer;
use AiMessDetector\Reporting\Formatter\Summary\OffenderListRenderer;
use AiMessDetector\Reporting\Formatter\Summary\SummaryFormatter;
use AiMessDetector\Reporting\Formatter\Summary\ViolationSummaryRenderer;
use AiMessDetector\Reporting\Formatter\Support\DetailedViolationRenderer;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Health\DecompositionItem;
use AiMessDetector\Reporting\Health\HealthScore;
use AiMessDetector\Reporting\Health\HealthScoreResolver;
use AiMessDetector\Reporting\Health\MetricHintProvider;
use AiMessDetector\Reporting\Health\NamespaceDrillDown;
use AiMessDetector\Reporting\Health\WorstOffender;
use AiMessDetector\Reporting\Report;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SummaryFormatter::class)]
final class SummaryFormatterTest extends TestCase
{
    private SummaryFormatter $formatter;
    private FormatterContext $plainContext;

    protected function setUp(): void
    {
        $registry = new RemediationTimeRegistry();
        $debtCalculator = new DebtCalculator($registry);
        $hintProvider = new MetricHintProvider();
        $namespaceDrillDown = new NamespaceDrillDown($hintProvider);
        $violationFilter = new ViolationFilter();
        $offenderListRenderer = new OffenderListRenderer($violationFilter, $namespaceDrillDown);
        $this->formatter = new SummaryFormatter(
            new DetailedViolationRenderer($debtCalculator),
            new HealthBarRenderer(new HealthScoreResolver($namespaceDrillDown)),
            $offenderListRenderer,
            $violationFilter,
            new ViolationSummaryRenderer($violationFilter, $registry),
            new HintRenderer($offenderListRenderer),
        );
        $this->plainContext = new FormatterContext(useColor: false, terminalWidth: 120);
    }

    public function testGetNameReturnsSummary(): void
    {
        self::assertSame('summary', $this->formatter->getName());
    }

    public function testGetDefaultGroupByReturnsNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatZeroViolations(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 42, duration: 1.5);

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('AI Mess Detector', $output);
        self::assertStringContainsString('42 files analyzed', $output);
        self::assertStringContainsString('1.5s', $output);
        self::assertStringContainsString('No violations found.', $output);
        self::assertStringNotContainsString('Worst namespaces', $output);
        self::assertStringNotContainsString('Worst classes', $output);
    }

    public function testFormatSingleFileNoNamespacesOrClasses(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 1,
            duration: 0.1,
            worstNamespaces: [
                new WorstOffender(
                    symbolPath: SymbolPath::forNamespace('App'),
                    file: null,
                    healthOverall: 30.0,
                    label: 'Weak',
                    reason: 'test',
                    violationCount: 1,
                    classCount: 1,
                ),
            ],
            worstClasses: [
                new WorstOffender(
                    symbolPath: SymbolPath::forClass('App', 'Foo'),
                    file: 'src/Foo.php',
                    healthOverall: 30.0,
                    label: 'Weak',
                    reason: 'test',
                    violationCount: 1,
                    classCount: 0,
                ),
            ],
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('1 file analyzed', $output);
        self::assertStringNotContainsString('1 files', $output);
        // Both worst sections skipped for single file
        self::assertStringNotContainsString('Worst namespaces', $output);
        self::assertStringNotContainsString('Worst classes', $output);
    }

    public function testFormatWithHealthScores(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 100,
            duration: 5.0,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Strong', 50.0, 30.0),
                'complexity' => new HealthScore('complexity', 85.0, 'Strong', 50.0, 25.0),
                'cohesion' => new HealthScore('cohesion', 40.0, 'Weak', 50.0, 25.0, [
                    new DecompositionItem('tcc.avg', 'TCC (avg)', 0.3, 'above 0.5', 'higher_is_better', 'methods share few common fields'),
                ]),
            ],
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('Health', $output);
        self::assertStringContainsString('72%', $output);
        self::assertStringContainsString('Strong', $output);
        self::assertStringContainsString('Complexity', $output);
        self::assertStringContainsString('85%', $output);
        self::assertStringContainsString('Cohesion', $output);
        self::assertStringContainsString('40%', $output);
        self::assertStringContainsString('TCC (avg)', $output);
        self::assertStringContainsString('0.3', $output);
        self::assertStringContainsString('above 0.5', $output);
    }

    public function testFormatWithWorstOffenders(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('src/Service/UserService.php', 42),
                    symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                    ruleName: 'complexity.cyclomatic',
                    violationCode: 'complexity.cyclomatic.method',
                    message: 'Too complex',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 50,
            duration: 2.0,
            worstNamespaces: [
                new WorstOffender(
                    symbolPath: SymbolPath::forNamespace('App\Service'),
                    file: null,
                    healthOverall: 35.0,
                    label: 'Weak',
                    reason: 'high complexity, low cohesion',
                    violationCount: 15,
                    classCount: 8,
                ),
            ],
            worstClasses: [
                new WorstOffender(
                    symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                    file: 'src/Service/UserService.php',
                    healthOverall: 22.0,
                    label: 'Critical',
                    reason: 'high coupling',
                    violationCount: 5,
                    classCount: 0,
                ),
            ],
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('Worst namespaces', $output);
        self::assertStringContainsString('App\Service', $output);
        self::assertStringContainsString('8 classes', $output);
        self::assertStringContainsString('15 violations', $output);

        self::assertStringContainsString('Worst classes', $output);
        self::assertStringContainsString('UserService', $output);
        self::assertStringContainsString('5 violations', $output);
    }

    public function testFormatViolationSummary(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App', 'A'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg1',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location('b.php', 1),
                    symbolPath: SymbolPath::forClass('App', 'B'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg2',
                    severity: Severity::Warning,
                ),
            ],
            filesAnalyzed: 2,
            duration: 0.1,
            techDebtMinutes: 90,
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('2 violations', $output);
        self::assertStringContainsString('1 error', $output);
        self::assertStringContainsString('1 warning', $output);
        self::assertStringContainsString('Tech debt: 1h 30min', $output);
    }

    public function testFormatPartialAnalysis(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 5,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Strong', 50.0, 30.0),
            ],
        );

        $context = new FormatterContext(useColor: false, partialAnalysis: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Warning shown
        self::assertStringContainsString('Health scores unavailable in partial analysis mode', $output);
        // Header annotated
        self::assertStringContainsString('(partial)', $output);
        // Hint to run full analysis
        self::assertStringContainsString('run full analysis', $output);
        // Health bars should NOT be shown
        self::assertStringNotContainsString('72%', $output);
    }

    public function testFormatMissingMetrics(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.3,
            healthScores: [],
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('Health: insufficient data', $output);
    }

    public function testFormatWithColorContainsAnsiCodes(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App', 'A'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 1,
            duration: 0.1,
        );

        $output = $this->formatter->format($report, $colorContext);

        self::assertStringContainsString("\e[", $output);
        // Error summary should be bold red
        self::assertStringContainsString("\e[1;31m", $output);
    }

    public function testFormatNoAnsiCodesWithColorDisabled(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App', 'A'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 1,
            duration: 0.1,
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringNotContainsString("\e[", $output);
    }

    public function testAsciiMode(): void
    {
        $originalEnv = getenv('AIMD_ASCII');
        putenv('AIMD_ASCII=1');

        try {
            $report = $this->createReport(
                violations: [],
                filesAnalyzed: 10,
                duration: 0.5,
                healthScores: [
                    'overall' => new HealthScore('overall', 72.0, 'Strong', 50.0, 30.0),
                    'complexity' => new HealthScore('complexity', 85.0, 'Strong', 50.0, 25.0),
                ],
            );

            $output = $this->formatter->format($report, $this->plainContext);

            self::assertStringContainsString('[', $output);
            self::assertStringContainsString('#', $output);
            self::assertStringNotContainsString('█', $output);
            self::assertStringNotContainsString('░', $output);
        } finally {
            if ($originalEnv === false) {
                putenv('AIMD_ASCII');
            } else {
                putenv('AIMD_ASCII=' . $originalEnv);
            }
        }
    }

    public function testHintsShownForViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App', 'A'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 1,
            duration: 0.1,
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('--detail', $output);
    }

    public function testHintsDrillDownForWorstOffender(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Strong', 50.0, 30.0),
            ],
            worstNamespaces: [
                new WorstOffender(
                    symbolPath: SymbolPath::forNamespace('App\Service'),
                    file: null,
                    healthOverall: 35.0,
                    label: 'Weak',
                    reason: 'high complexity',
                    violationCount: 5,
                    classCount: 3,
                ),
            ],
        );

        $output = $this->formatter->format($report, $this->plainContext);

        // Uses single quotes for shell escaping
        self::assertStringContainsString("--namespace='App\\Service'", $output);
    }

    public function testNamespaceFilterBoundaryAware(): void
    {
        $offenderMatch = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\Payment\Gateway'),
            file: null,
            healthOverall: 30.0,
            label: 'Weak',
            reason: 'test',
            violationCount: 3,
            classCount: 2,
        );

        $offenderNoMatch = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\PaymentGateway'),
            file: null,
            healthOverall: 25.0,
            label: 'Critical',
            reason: 'test',
            violationCount: 5,
            classCount: 4,
        );

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 50,
            duration: 1.0,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Strong', 50.0, 30.0),
            ],
            worstNamespaces: [$offenderMatch, $offenderNoMatch],
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Payment', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('App\Payment\Gateway', $output);
        self::assertStringNotContainsString('App\PaymentGateway', $output);
    }

    public function testNamespaceFilterAppliesToViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'In scope',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location('b.php', 1),
                    symbolPath: SymbolPath::forClass('App\Controller', 'HomeController'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Out of scope',
                    severity: Severity::Warning,
                ),
            ],
            filesAnalyzed: 10,
            duration: 0.5,
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Only 1 violation in scope
        self::assertStringContainsString('1 violation', $output);
        self::assertStringContainsString('1 error', $output);
        self::assertStringNotContainsString('warning', $output);
    }

    public function testClassFilterAppliesToViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Match',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location('b.php', 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'OrderService'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'No match',
                    severity: Severity::Warning,
                ),
            ],
            filesAnalyzed: 10,
            duration: 0.5,
        );

        $context = new FormatterContext(useColor: false, class: 'App\Service\UserService', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('1 violation', $output);
        self::assertStringContainsString('1 error', $output);
        self::assertStringNotContainsString('warning', $output);
    }

    public function testNoViolationsInScopeMessage(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App\Other', 'Foo'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 10,
            duration: 0.5,
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('No violations in this scope.', $output);
    }

    public function testClassFilterExactMatch(): void
    {
        $offenderMatch = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            file: 'src/Service/UserService.php',
            healthOverall: 22.0,
            label: 'Critical',
            reason: 'test',
            violationCount: 5,
            classCount: 0,
        );

        $offenderNoMatch = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\Service', 'OrderService'),
            file: 'src/Service/OrderService.php',
            healthOverall: 30.0,
            label: 'Weak',
            reason: 'test',
            violationCount: 3,
            classCount: 0,
        );

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Strong', 50.0, 30.0),
            ],
            worstClasses: [$offenderMatch, $offenderNoMatch],
        );

        $userServiceCanonical = $offenderMatch->symbolPath->toString();
        $context = new FormatterContext(useColor: false, class: $userServiceCanonical, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('UserService', $output);
        self::assertStringNotContainsString('OrderService', $output);
    }

    public function testPlusNMoreForTruncatedList(): void
    {
        $offenders = [];
        for ($i = 0; $i < 5; $i++) {
            $offenders[] = new WorstOffender(
                symbolPath: SymbolPath::forNamespace('App\Ns' . $i),
                file: null,
                healthOverall: 20.0 + $i * 5,
                label: 'Weak',
                reason: 'test',
                violationCount: $i + 1,
                classCount: $i + 2,
            );
        }

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 50,
            duration: 1.0,
            healthScores: [
                'overall' => new HealthScore('overall', 60.0, 'Acceptable', 50.0, 30.0),
            ],
            worstNamespaces: $offenders,
        );

        $output = $this->formatter->format($report, $this->plainContext);

        // First 3 shown
        self::assertStringContainsString('App\Ns0', $output);
        self::assertStringContainsString('App\Ns1', $output);
        self::assertStringContainsString('App\Ns2', $output);
        // 4th and 5th not shown
        self::assertStringNotContainsString('App\Ns3', $output);
        self::assertStringNotContainsString('App\Ns4', $output);
        // "+2 more" shown
        self::assertStringContainsString('+2 more', $output);
    }

    public function testExactly3OffendersNoPlusMore(): void
    {
        $offenders = [];
        for ($i = 0; $i < 3; $i++) {
            $offenders[] = new WorstOffender(
                symbolPath: SymbolPath::forNamespace('App\Ns' . $i),
                file: null,
                healthOverall: 20.0 + $i * 5,
                label: 'Weak',
                reason: 'test',
                violationCount: 1,
                classCount: 1,
            );
        }

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 50,
            duration: 1.0,
            healthScores: [
                'overall' => new HealthScore('overall', 60.0, 'Acceptable', 50.0, 30.0),
            ],
            worstNamespaces: $offenders,
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringNotContainsString('+', $output);
    }

    public function testTechDebtZeroNotShown(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App', 'A'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg',
                    severity: Severity::Warning,
                ),
            ],
            filesAnalyzed: 1,
            duration: 0.1,
            techDebtMinutes: 0,
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringNotContainsString('Tech debt', $output);
    }

    public function testHtmlHintAlwaysShown(): void
    {
        // Even without health scores
        $report = $this->createReport(violations: [], filesAnalyzed: 10, duration: 0.5);

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('--format=health', $output);
    }

    public function testPartialAnalysisHintForFullRun(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 5, duration: 0.5);

        $context = new FormatterContext(useColor: false, partialAnalysis: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('run full analysis', $output);
    }

    public function testHeaderAnnotatedWithNamespaceFilter(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 10, duration: 0.5);

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('[namespace: App\Service]', $output);
    }

    public function testHeaderAnnotatedWithClassFilter(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 10, duration: 0.5);

        $context = new FormatterContext(useColor: false, class: 'App\Service\UserService', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('[class: App\Service\UserService]', $output);
    }

    public function testNanScoreRenderedAsDash(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', \NAN, 'Unknown', 50.0, 30.0),
            ],
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('—%', $output);
        self::assertStringNotContainsString('NAN', $output);
    }

    public function testScoreColorBoundaryAtWarningThreshold(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        // Score exactly at warning threshold (50.0) should be yellow (not green)
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 50.0, 'Weak', 50.0, 30.0),
            ],
        );

        $output = $this->formatter->format($report, $colorContext);

        // Yellow = \e[33m, Green = \e[32m
        // 50.0 is NOT > 50.0, so should be yellow
        self::assertStringContainsString("\e[33m50%\e[0m", $output);
    }

    public function testScoreColorAboveWarningThresholdIsGreen(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 50.1, 'Acceptable', 50.0, 30.0),
            ],
        );

        $output = $this->formatter->format($report, $colorContext);

        // 50.1 > 50.0 → green
        self::assertStringContainsString("\e[32m", $output);
    }

    public function testScoreColorAtErrorThresholdIsRed(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 30.0, 'Weak', 50.0, 30.0),
            ],
        );

        $output = $this->formatter->format($report, $colorContext);

        // 30.0 is NOT > 30.0 → red
        self::assertStringContainsString("\e[31m30%\e[0m", $output);
    }

    public function testTechDebtShownInScopedMode(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'Foo'),
                    ruleName: 'complexity.cyclomatic',
                    violationCode: 'complexity.cyclomatic.method',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location('b.php', 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'Bar'),
                    ruleName: 'coupling.cbo',
                    violationCode: 'coupling.cbo.class',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 10,
            duration: 0.5,
            techDebtMinutes: 120,
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Scoped tech debt computed from filtered violations (30min + 45min = 1h 15min)
        self::assertStringContainsString('Tech debt: 1h 15min', $output);
    }

    public function testTechDebtShownInClassScopedMode(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                    ruleName: 'code-smell.god-class',
                    violationCode: 'code-smell.god-class',
                    message: 'God class',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location('b.php', 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'OrderService'),
                    ruleName: 'coupling.cbo',
                    violationCode: 'coupling.cbo.class',
                    message: 'Out of scope',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 10,
            duration: 0.5,
        );

        $context = new FormatterContext(useColor: false, class: 'App\Service\UserService', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Only god-class violation matches (120min = 2h)
        self::assertStringContainsString('Tech debt: 2h', $output);
        self::assertStringContainsString('1 violation', $output);
    }

    public function testTechDebtHiddenInScopedModeWithNoViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App\Other', 'Foo'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 10,
            duration: 0.5,
            techDebtMinutes: 120,
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // No violations in scope, so no tech debt line
        self::assertStringNotContainsString('Tech debt', $output);
    }

    public function testDetailAppendsViolationSection(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('src/Foo.php', 10),
                    symbolPath: SymbolPath::forClass('App', 'Foo'),
                    ruleName: 'complexity.cyclomatic',
                    violationCode: 'complexity.cyclomatic.method',
                    message: 'Cyclomatic complexity is 15',
                    severity: Severity::Error,
                    recommendation: 'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
                ),
            ],
            filesAnalyzed: 1,
            duration: 0.1,
        );

        $context = new FormatterContext(useColor: false, terminalWidth: 120, detailLimit: 0);
        $output = $this->formatter->format($report, $context);

        // Should contain summary section
        self::assertStringContainsString('1 violation', $output);

        // Should contain detailed violations section
        self::assertStringContainsString('Violations', $output);
        self::assertStringContainsString('src/Foo.php (1 violation)', $output);
        self::assertStringContainsString('too many code paths', $output);
        self::assertStringContainsString('[complexity.cyclomatic.method]', $output);
        self::assertStringContainsString('ERROR', $output);
    }

    public function testDetailNotShownForEmptyReport(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
        );

        $context = new FormatterContext(useColor: false, terminalWidth: 120, detailLimit: 0);
        $output = $this->formatter->format($report, $context);

        // Should NOT contain "Violations" section
        self::assertStringNotContainsString('Violations', $output);
        self::assertStringContainsString('No violations found.', $output);
    }

    public function testDetailWithNamespaceFilterShowsScopedViolationsOnly(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'In scope',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location('b.php', 1),
                    symbolPath: SymbolPath::forClass('App\Other', 'Foo'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Out of scope',
                    severity: Severity::Warning,
                ),
            ],
            filesAnalyzed: 10,
            duration: 0.5,
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120, detailLimit: 0);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('In scope', $output);
        self::assertStringNotContainsString('Out of scope', $output);
    }

    public function testDetailHintNotShownWhenDetailActive(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location('a.php', 1),
                    symbolPath: SymbolPath::forClass('App', 'A'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
            ],
            filesAnalyzed: 1,
            duration: 0.1,
        );

        $context = new FormatterContext(useColor: false, terminalWidth: 120, detailLimit: 0);
        $output = $this->formatter->format($report, $context);

        // Should NOT hint --detail since we're already in detail mode
        self::assertStringNotContainsString('--detail to see all violations', $output);
    }

    public function testNamespaceFilterShowsNamespaceHealthScores(): void
    {
        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \AiMessDetector\Core\Metric\MetricBag::fromArray([
            'health.overall' => 45.0,
            'health.complexity' => 60.0,
            'health.cohesion' => 30.0,
            'health.coupling' => 55.0,
            'health.typing' => 70.0,
            'health.maintainability' => 50.0,
            'classCount' => 5,
        ]);

        $metrics = $this->createMock(\AiMessDetector\Core\Metric\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturnCallback(
            static fn(SymbolPath $sp): bool => $sp->toCanonical() === $nsPath->toCanonical(),
        );
        $metrics->method('get')->willReturnCallback(
            static fn(SymbolPath $sp): \AiMessDetector\Core\Metric\MetricBag => $sp->toCanonical() === $nsPath->toCanonical()
                ? $nsMetrics
                : new \AiMessDetector\Core\Metric\MetricBag(),
        );
        $metrics->method('all')->willReturnCallback(
            static fn(\AiMessDetector\Core\Symbol\SymbolType $type): array => $type === \AiMessDetector\Core\Symbol\SymbolType::Namespace_
                ? [new \AiMessDetector\Core\Symbol\SymbolInfo($nsPath, 'src/Service', 0)]
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
                'overall' => new HealthScore('overall', 72.0, 'Acceptable', 50.0, 30.0),
                'complexity' => new HealthScore('complexity', 85.0, 'Strong', 50.0, 25.0),
            ],
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Should show namespace-level score (45%) not project-level (72%)
        self::assertStringContainsString('45%', $output);
        self::assertStringNotContainsString('72%', $output);
        // Should show namespace-level dimension scores
        self::assertStringContainsString('60%', $output); // complexity
        self::assertStringContainsString('30%', $output); // cohesion
    }

    public function testNamespaceFilterBuildsWorstClassesFromMetrics(): void
    {
        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classMetrics = \AiMessDetector\Core\Metric\MetricBag::fromArray([
            'health.overall' => 25.0,
            'health.complexity' => 20.0,
            'health.cohesion' => 15.0,
        ]);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \AiMessDetector\Core\Metric\MetricBag::fromArray([
            'health.overall' => 40.0,
            'health.complexity' => 50.0,
        ]);

        $metrics = $this->createMock(\AiMessDetector\Core\Metric\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturnCallback(
            static fn(SymbolPath $sp): bool => $sp->toCanonical() === $nsPath->toCanonical(),
        );
        $metrics->method('get')->willReturnCallback(
            static fn(SymbolPath $sp): \AiMessDetector\Core\Metric\MetricBag => match ($sp->toCanonical()) {
                $nsPath->toCanonical() => $nsMetrics,
                $classPath->toCanonical() => $classMetrics,
                default => new \AiMessDetector\Core\Metric\MetricBag(),
            },
        );
        $metrics->method('all')->willReturnCallback(
            static fn(\AiMessDetector\Core\Symbol\SymbolType $type): array => $type === \AiMessDetector\Core\Symbol\SymbolType::Class_
                ? [new \AiMessDetector\Core\Symbol\SymbolInfo($classPath, 'src/Service/UserService.php', 1)]
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
                'overall' => new HealthScore('overall', 72.0, 'Acceptable', 50.0, 30.0),
            ],
            worstClasses: [],
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Should show UserService as worst class even though it's not in global top
        self::assertStringContainsString('UserService', $output);
        self::assertStringContainsString('Worst classes', $output);
    }

    public function testNamespaceFilterFallsBackToProjectWhenNoNsMetrics(): void
    {
        $metrics = $this->createMock(\AiMessDetector\Core\Metric\MetricRepositoryInterface::class);
        $metrics->method('has')->willReturn(false);
        $metrics->method('get')->willReturn(new \AiMessDetector\Core\Metric\MetricBag());
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
                'overall' => new HealthScore('overall', 72.0, 'Acceptable', 50.0, 30.0),
            ],
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\NonExistent', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // No health data for non-existent namespace — shows "insufficient data"
        self::assertStringContainsString('Health: insufficient data', $output);
        self::assertStringNotContainsString('72%', $output);
    }

    public function testDetailTruncationShowsRemainingCount(): void
    {
        $violations = [];
        for ($i = 0; $i < 8; $i++) {
            $violations[] = new Violation(
                location: new Location('src/File' . $i . '.php', $i + 1),
                symbolPath: SymbolPath::forClass('App', 'Class' . $i),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Too complex #' . $i,
                severity: Severity::Error,
                recommendation: 'Cyclomatic complexity too high #' . $i,
            );
        }

        $report = $this->createReport(
            violations: $violations,
            filesAnalyzed: 8,
            duration: 0.5,
        );

        $context = new FormatterContext(useColor: false, terminalWidth: 120, detailLimit: 5);
        $output = $this->formatter->format($report, $context);

        // Should show truncation message: 8 total - 5 shown = 3 remaining
        self::assertStringContainsString('... and 3 more. Use --detail=all', $output);
    }

    /**
     * @param list<Violation> $violations
     * @param array<string, HealthScore> $healthScores
     * @param list<WorstOffender> $worstNamespaces
     * @param list<WorstOffender> $worstClasses
     */
    private function createReport(
        array $violations = [],
        int $filesAnalyzed = 0,
        float $duration = 0.0,
        array $healthScores = [],
        array $worstNamespaces = [],
        array $worstClasses = [],
        int $techDebtMinutes = 0,
    ): Report {
        $errorCount = 0;
        $warningCount = 0;

        foreach ($violations as $v) {
            if ($v->severity === Severity::Error) {
                $errorCount++;
            } else {
                $warningCount++;
            }
        }

        return new Report(
            violations: $violations,
            filesAnalyzed: $filesAnalyzed,
            filesSkipped: 0,
            duration: $duration,
            errorCount: $errorCount,
            warningCount: $warningCount,
            healthScores: $healthScores,
            worstNamespaces: $worstNamespaces,
            worstClasses: $worstClasses,
            techDebtMinutes: $techDebtMinutes,
        );
    }
}
