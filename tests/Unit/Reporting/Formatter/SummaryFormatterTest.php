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
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Summary\HealthBarRenderer;
use Qualimetrix\Reporting\Formatter\Summary\HintRenderer;
use Qualimetrix\Reporting\Formatter\Summary\OffenderListRenderer;
use Qualimetrix\Reporting\Formatter\Summary\SummaryFormatter;
use Qualimetrix\Reporting\Formatter\Summary\TopIssuesRenderer;
use Qualimetrix\Reporting\Formatter\Summary\ViolationSummaryRenderer;
use Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Health\WorstOffender;
use Qualimetrix\Reporting\Report;

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
            new TopIssuesRenderer(),
            new ViolationSummaryRenderer($violationFilter, $registry),
            new HintRenderer($offenderListRenderer),
        );
        $this->plainContext = new FormatterContext(useColor: false, terminalWidth: 120);
    }

    #[Test]
    public function itReturnsNameAsSummary(): void
    {
        self::assertSame('summary', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsDefaultGroupByAsNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itFormatsZeroViolations(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 42, duration: 1.5);

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('Qualimetrix', $output);
        self::assertStringContainsString('42 files analyzed', $output);
        self::assertStringContainsString('1.5s', $output);
        self::assertStringContainsString('No violations found.', $output);
        self::assertStringNotContainsString('Worst namespaces', $output);
        self::assertStringNotContainsString('Worst classes', $output);
    }

    #[Test]
    public function itFormatsSingleFileWithoutNamespacesOrClasses(): void
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
                    label: 'Poor',
                    reason: 'test',
                    violationCount: 1,
                    classCount: 1,
                ),
            ],
            worstClasses: [
                new WorstOffender(
                    symbolPath: SymbolPath::forClass('App', 'Foo'),
                    file: RelativePath::fromString('src/Foo.php'),
                    healthOverall: 30.0,
                    label: 'Poor',
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

    #[Test]
    public function itFormatsWithHealthScores(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 100,
            duration: 5.0,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Excellent', 50.0, 30.0),
                'complexity' => new HealthScore('complexity', 85.0, 'Excellent', 50.0, 25.0),
                'cohesion' => new HealthScore('cohesion', 40.0, 'Poor', 50.0, 25.0, [
                    new DecompositionItem('tcc.avg', 'TCC (avg)', 0.3, 'above 0.5', 'higher_is_better', 'methods share few common fields'),
                ]),
            ],
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('Health', $output);
        self::assertStringContainsString('72%', $output);
        self::assertStringContainsString('Excellent', $output);
        self::assertStringContainsString('Complexity', $output);
        self::assertStringContainsString('85%', $output);
        self::assertStringContainsString('Cohesion', $output);
        self::assertStringContainsString('40%', $output);
        self::assertStringContainsString('TCC (avg)', $output);
        self::assertStringContainsString('0.3', $output);
        self::assertStringContainsString('above 0.5', $output);
    }

    #[Test]
    public function itFormatsWithWorstOffenders(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
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
                    label: 'Poor',
                    reason: 'high complexity, low cohesion',
                    violationCount: 15,
                    classCount: 8,
                ),
            ],
            worstClasses: [
                new WorstOffender(
                    symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                    file: RelativePath::fromString('src/Service/UserService.php'),
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

    #[Test]
    public function itFormatsViolationSummary(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
                    symbolPath: SymbolPath::forClass('App', 'A'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Msg1',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location(RelativePath::fromString('b.php'), 1),
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

    #[Test]
    public function itFormatsScopedReporting(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 5,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Excellent', 50.0, 30.0),
            ],
        );

        $context = new FormatterContext(useColor: false, scopedReporting: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Header annotated with scoped label
        self::assertStringContainsString('(scoped)', $output);
        // Health bars ARE shown (full graph is always available)
        self::assertStringContainsString('72%', $output);
        // Hint about scoped analysis
        self::assertStringContainsString('scoped analysis', $output);
    }

    #[Test]
    public function itFormatsMissingMetrics(): void
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

    #[Test]
    public function itFormatsWithColorContainingAnsiCodes(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
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

    #[Test]
    public function itFormatsWithoutAnsiCodesWhenColorDisabled(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
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

    #[Test]
    public function itRendersInAsciiMode(): void
    {
        $originalEnv = getenv('QMX_ASCII');
        putenv('QMX_ASCII=1');

        try {
            $report = $this->createReport(
                violations: [],
                filesAnalyzed: 10,
                duration: 0.5,
                healthScores: [
                    'overall' => new HealthScore('overall', 72.0, 'Excellent', 50.0, 30.0),
                    'complexity' => new HealthScore('complexity', 85.0, 'Excellent', 50.0, 25.0),
                ],
            );

            $output = $this->formatter->format($report, $this->plainContext);

            self::assertStringContainsString('[', $output);
            self::assertStringContainsString('#', $output);
            self::assertStringNotContainsString('█', $output);
            self::assertStringNotContainsString('░', $output);
        } finally {
            if ($originalEnv === false) {
                putenv('QMX_ASCII');
            } else {
                putenv('QMX_ASCII=' . $originalEnv);
            }
        }
    }

    #[Test]
    public function itShowsHintsForViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
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

    #[Test]
    public function itShowsDrillDownHintForWorstOffender(): void
    {
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Excellent', 50.0, 30.0),
            ],
            worstNamespaces: [
                new WorstOffender(
                    symbolPath: SymbolPath::forNamespace('App\Service'),
                    file: null,
                    healthOverall: 35.0,
                    label: 'Poor',
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

    #[Test]
    public function itAppliesNamespaceFilterBoundaryAware(): void
    {
        $offenderMatch = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\Payment\Gateway'),
            file: null,
            healthOverall: 30.0,
            label: 'Poor',
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
                'overall' => new HealthScore('overall', 72.0, 'Excellent', 50.0, 30.0),
            ],
            worstNamespaces: [$offenderMatch, $offenderNoMatch],
        );

        $context = new FormatterContext(useColor: false, namespace: 'App\Payment', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('App\Payment\Gateway', $output);
        self::assertStringNotContainsString('App\PaymentGateway', $output);
    }

    #[Test]
    public function itAppliesNamespaceFilterToViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'In scope',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location(RelativePath::fromString('b.php'), 1),
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

    #[Test]
    public function itAppliesClassFilterToViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
                    symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'Match',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location(RelativePath::fromString('b.php'), 1),
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

    #[Test]
    public function itShowsNoViolationsInScopeMessage(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
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

    #[Test]
    public function itAppliesClassFilterWithExactMatch(): void
    {
        $offenderMatch = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            file: RelativePath::fromString('src/Service/UserService.php'),
            healthOverall: 22.0,
            label: 'Critical',
            reason: 'test',
            violationCount: 5,
            classCount: 0,
        );

        $offenderNoMatch = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\Service', 'OrderService'),
            file: RelativePath::fromString('src/Service/OrderService.php'),
            healthOverall: 30.0,
            label: 'Poor',
            reason: 'test',
            violationCount: 3,
            classCount: 0,
        );

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 72.0, 'Excellent', 50.0, 30.0),
            ],
            worstClasses: [$offenderMatch, $offenderNoMatch],
        );

        $userServiceCanonical = $offenderMatch->symbolPath->toString();
        $context = new FormatterContext(useColor: false, class: $userServiceCanonical, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('UserService', $output);
        self::assertStringNotContainsString('OrderService', $output);
    }

    #[Test]
    public function itShowsPlusNMoreForTruncatedList(): void
    {
        $offenders = [];
        for ($i = 0; $i < 5; $i++) {
            $offenders[] = new WorstOffender(
                symbolPath: SymbolPath::forNamespace('App\Ns' . $i),
                file: null,
                healthOverall: 20.0 + $i * 5,
                label: 'Poor',
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
                'overall' => new HealthScore('overall', 60.0, 'Fair', 50.0, 30.0),
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

    #[Test]
    public function itShowsNoPlusMoreForExactly3Offenders(): void
    {
        $offenders = [];
        for ($i = 0; $i < 3; $i++) {
            $offenders[] = new WorstOffender(
                symbolPath: SymbolPath::forNamespace('App\Ns' . $i),
                file: null,
                healthOverall: 20.0 + $i * 5,
                label: 'Poor',
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
                'overall' => new HealthScore('overall', 60.0, 'Fair', 50.0, 30.0),
            ],
            worstNamespaces: $offenders,
        );

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringNotContainsString('+', $output);
    }

    #[Test]
    public function itDoesNotShowZeroTechDebt(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
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

    #[Test]
    public function itAlwaysShowsHtmlHint(): void
    {
        // Even without health scores
        $report = $this->createReport(violations: [], filesAnalyzed: 10, duration: 0.5);

        $output = $this->formatter->format($report, $this->plainContext);

        self::assertStringContainsString('--format=html', $output);
    }

    #[Test]
    public function itShowsScopedReportingHint(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 5, duration: 0.5);

        $context = new FormatterContext(useColor: false, scopedReporting: true, terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('scoped analysis', $output);
    }

    #[Test]
    public function itAnnotatesHeaderWithNamespaceFilter(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 10, duration: 0.5);

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('[namespace: App\Service]', $output);
    }

    #[Test]
    public function itAnnotatesHeaderWithClassFilter(): void
    {
        $report = $this->createReport(violations: [], filesAnalyzed: 10, duration: 0.5);

        $context = new FormatterContext(useColor: false, class: 'App\Service\UserService', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('[class: App\Service\UserService]', $output);
    }

    #[Test]
    public function itRendersNanScoreAsDash(): void
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

    #[Test]
    public function itColorsScoreBoundaryAtWarningThreshold(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        // Score exactly at warning threshold (50.0) should be yellow (not green)
        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 50.0, 'Poor', 50.0, 30.0),
            ],
        );

        $output = $this->formatter->format($report, $colorContext);

        // Yellow = \e[33m, Green = \e[32m
        // 50.0 is NOT > 50.0, so should be yellow
        self::assertStringContainsString("\e[33m50%\e[0m", $output);
    }

    #[Test]
    public function itColorsScoreGreenAboveWarningThreshold(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 50.1, 'Fair', 50.0, 30.0),
            ],
        );

        $output = $this->formatter->format($report, $colorContext);

        // 50.1 > 50.0 → green
        self::assertStringContainsString("\e[32m", $output);
    }

    #[Test]
    public function itColorsScoreRedAtErrorThreshold(): void
    {
        $colorContext = new FormatterContext(useColor: true, terminalWidth: 120);

        $report = $this->createReport(
            violations: [],
            filesAnalyzed: 10,
            duration: 0.5,
            healthScores: [
                'overall' => new HealthScore('overall', 30.0, 'Poor', 50.0, 30.0),
            ],
        );

        $output = $this->formatter->format($report, $colorContext);

        // 30.0 is NOT > 30.0 → red
        self::assertStringContainsString("\e[31m30%\e[0m", $output);
    }

    #[Test]
    public function itShowsTechDebtInScopedMode(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'Foo'),
                    ruleName: 'complexity.cyclomatic',
                    violationCode: 'complexity.cyclomatic.method',
                    message: 'Msg',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location(RelativePath::fromString('b.php'), 1),
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

    #[Test]
    public function itShowsTechDebtInClassScopedMode(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
                    symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                    ruleName: 'design.god-class',
                    violationCode: 'design.god-class',
                    message: 'God class',
                    severity: Severity::Error,
                ),
                new Violation(
                    location: new Location(RelativePath::fromString('b.php'), 1),
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

    #[Test]
    public function itHidesTechDebtInScopedModeWithNoViolations(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
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

    #[Test]
    public function itAppendsViolationSectionInDetailMode(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('src/Foo.php'), 10),
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

    #[Test]
    public function itDoesNotShowDetailForEmptyReport(): void
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

    #[Test]
    public function itShowsScopedViolationsOnlyInDetailWithNamespaceFilter(): void
    {
        // Violations are pre-filtered by ResultPresenter before reaching the formatter.
        // Only in-scope violations are included in the report.
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
                    symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                    ruleName: 'test',
                    violationCode: 'test',
                    message: 'In scope',
                    severity: Severity::Error,
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

    #[Test]
    public function itDoesNotShowDetailHintWhenDetailActive(): void
    {
        $report = $this->createReport(
            violations: [
                new Violation(
                    location: new Location(RelativePath::fromString('a.php'), 1),
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

    #[Test]
    public function itShowsNamespaceHealthScoresWhenFilteringByNamespace(): void
    {
        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \Qualimetrix\Core\Metric\MetricBag::fromArray([
            'health.overall' => 45.0,
            'health.complexity' => 60.0,
            'health.cohesion' => 30.0,
            'health.coupling' => 55.0,
            'health.typing' => 70.0,
            'health.maintainability' => 50.0,
            'classCount' => 5,
        ]);

        $metrics = self::createStub(\Qualimetrix\Core\Metric\MetricRepositoryInterface::class);
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
                'complexity' => new HealthScore('complexity', 85.0, 'Excellent', 50.0, 25.0),
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

    #[Test]
    public function itBuildsWorstClassesFromMetricsWhenFilteringByNamespace(): void
    {
        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classMetrics = \Qualimetrix\Core\Metric\MetricBag::fromArray([
            'health.overall' => 25.0,
            'health.complexity' => 20.0,
            'health.cohesion' => 15.0,
        ]);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsMetrics = \Qualimetrix\Core\Metric\MetricBag::fromArray([
            'health.overall' => 40.0,
            'health.complexity' => 50.0,
        ]);

        $metrics = self::createStub(\Qualimetrix\Core\Metric\MetricRepositoryInterface::class);
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

        $context = new FormatterContext(useColor: false, namespace: 'App\Service', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // Should show UserService as worst class even though it's not in global top
        self::assertStringContainsString('UserService', $output);
        self::assertStringContainsString('Worst classes', $output);
    }

    #[Test]
    public function itFallsBackToProjectWhenNoNsMetricsForNamespaceFilter(): void
    {
        $metrics = self::createStub(\Qualimetrix\Core\Metric\MetricRepositoryInterface::class);
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

        $context = new FormatterContext(useColor: false, namespace: 'App\NonExistent', terminalWidth: 120);
        $output = $this->formatter->format($report, $context);

        // No health data for non-existent namespace — shows "insufficient data"
        self::assertStringContainsString('Health: insufficient data', $output);
        self::assertStringNotContainsString('72%', $output);
    }

    #[Test]
    public function itShowsRemainingCountOnDetailTruncation(): void
    {
        $violations = [];
        for ($i = 0; $i < 8; $i++) {
            $violations[] = new Violation(
                location: new Location(RelativePath::fromString('src/File' . $i . '.php'), $i + 1),
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

    #[Test]
    public function itIncludesAllRulesInDebtBreakdownWhenDetailLimitTruncates(): void
    {
        $violations = [];

        // 3 violations of rule A
        for ($i = 0; $i < 3; $i++) {
            $violations[] = new Violation(
                location: new Location(RelativePath::fromString('src/Foo' . $i . '.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'Foo' . $i),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complex',
                severity: Severity::Error,
            );
        }

        // 1 violation of rule B (will be beyond detailLimit=2)
        $violations[] = new Violation(
            location: new Location(RelativePath::fromString('src/Bar.php'), 5),
            symbolPath: SymbolPath::forClass('App', 'Bar'),
            ruleName: 'design.lcom',
            violationCode: 'design.lcom',
            message: 'LCOM high',
            severity: Severity::Warning,
        );

        $report = $this->createReport(violations: $violations, filesAnalyzed: 4, duration: 0.01);
        $context = new FormatterContext(useColor: false, terminalWidth: 120, detailLimit: 2);
        $output = $this->formatter->format($report, $context);

        // Debt breakdown must show ALL rules, not just those within the display limit
        self::assertStringContainsString('Technical debt by rule:', $output);
        self::assertStringContainsString('complexity.cyclomatic', $output);
        self::assertStringContainsString('design.lcom', $output);
        // Violation counts in breakdown must reflect all violations
        self::assertStringContainsString('3 violations', $output);
        self::assertStringContainsString('1 violation)', $output);
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
