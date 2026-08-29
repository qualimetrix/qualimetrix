<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Filter\FindingFilter;
use Qualimetrix\Reporting\Formatter\Summary\FindingSummaryRenderer;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(FindingSummaryRenderer::class)]
final class FindingSummaryRendererTest extends TestCase
{
    private FindingSummaryRenderer $renderer;
    private AnsiColor $color;

    protected function setUp(): void
    {
        $this->renderer = new FindingSummaryRenderer(
            new FindingFilter(),
            new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues()),
        );
        $this->color = new AnsiColor(false);
    }

    #[Test]
    public function itShowsNoFindingsFoundForEmptyReport(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('No violations found.', $output);
    }

    #[Test]
    public function itShowsNoFindingsInNamespaceScope(): void
    {
        // Report has findings in a different namespace so isEmpty() is false,
        // but the filtered findings for this namespace are empty.
        $otherFinding = $this->createFinding(Severity::Error, 'Other\\Namespace', 'OtherService');

        $report = new Report(
            findings: [$otherFinding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
        );

        $context = new FormatterContext(namespace: 'App\\Service');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('No violations in this scope.', $output);
    }

    #[Test]
    public function itShowsNoFindingsInClassScope(): void
    {
        $otherFinding = $this->createFinding(Severity::Error, 'App\\Service', 'OtherService');

        $report = new Report(
            findings: [$otherFinding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
        );

        $context = new FormatterContext(class: 'App\\Service\\UserService');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('No violations in this scope.', $output);
    }

    #[Test]
    public function itShowsNoFindingsFoundForNonEmptyReportWithNoScope(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        self::assertSame(['No violations found.', ''], $lines);
    }

    #[Test]
    public function itFormatsSingleError(): void
    {
        $finding = $this->createFinding(Severity::Error);

        $report = new Report(
            findings: [$finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('1 violation', $output);
        self::assertStringNotContainsString('1 violations', $output);
        self::assertStringContainsString('1 error', $output);
        self::assertStringNotContainsString('1 errors', $output);
    }

    #[Test]
    public function itFormatsSingleWarning(): void
    {
        $finding = $this->createFinding(Severity::Warning);

        $report = new Report(
            findings: [$finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 1,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('1 violation', $output);
        self::assertStringContainsString('1 warning', $output);
        self::assertStringNotContainsString('1 warnings', $output);
    }

    #[Test]
    public function itFormatsSingleInfo(): void
    {
        $finding = $this->createFinding(Severity::Info);

        $report = new Report(
            findings: [$finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('1 violation', $output);
        self::assertStringContainsString('1 info', $output);
        self::assertStringNotContainsString('1 infos', $output);
    }

    #[Test]
    public function itFormatsMixedErrorsAndWarnings(): void
    {
        $findings = [
            $this->createFinding(Severity::Error),
            $this->createFinding(Severity::Error),
            $this->createFinding(Severity::Warning),
            $this->createFinding(Severity::Warning),
            $this->createFinding(Severity::Warning),
        ];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 2,
            warningCount: 3,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('5 violations', $output);
        self::assertStringContainsString('2 errors', $output);
        self::assertStringContainsString('3 warnings', $output);
    }

    #[Test]
    public function itDisplaysTechDebt(): void
    {
        $findings = [$this->createFinding(Severity::Error)];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            techDebtMinutes: 90,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('Tech debt: 1h 30min', $output);
    }

    #[Test]
    public function itDisplaysTechDebtWithDensity(): void
    {
        $findings = [$this->createFinding(Severity::Error)];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            techDebtMinutes: 60,
            debtPer1kLoc: 2.5,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('Tech debt: 1h', $output);
        self::assertStringContainsString('2.5 min/kLOC to fix', $output);
    }

    #[Test]
    public function itDoesNotDisplayZeroTechDebt(): void
    {
        $findings = [$this->createFinding(Severity::Warning)];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 1,
            techDebtMinutes: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        $output = implode("\n", $lines);
        self::assertStringNotContainsString('Tech debt', $output);
    }

    #[Test]
    public function itCalculatesScopedDebt(): void
    {
        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App\\Service', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'CCN is 30',
            severity: Severity::Error,
            metricValue: 30,
            threshold: 10,
        );

        $report = new Report(
            findings: [$finding],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
            techDebtMinutes: 100,
        );

        // Scoped context — uses calculateScopedDebt instead of report.techDebtMinutes
        $context = new FormatterContext(namespace: 'App\\Service');
        $lines = [];

        $this->renderer->render($report, $context, $this->color, $lines);

        $output = implode("\n", $lines);
        // Scoped debt is calculated from the finding, not from report total
        self::assertStringContainsString('Tech debt:', $output);
    }

    #[Test]
    public function itColorsSummaryBoldForErrors(): void
    {
        $ansiColor = new AnsiColor(true);
        $findings = [$this->createFinding(Severity::Error)];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 1,
            warningCount: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $ansiColor, $lines);

        $output = implode("\n", $lines);
        // Bold red for errors
        self::assertStringContainsString("\e[1;31m", $output);
    }

    #[Test]
    public function itColorsSummaryBoldForWarningsOnly(): void
    {
        $ansiColor = new AnsiColor(true);
        $findings = [$this->createFinding(Severity::Warning)];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 1,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $ansiColor, $lines);

        $output = implode("\n", $lines);
        // Bold yellow for warnings only
        self::assertStringContainsString("\e[1;33m", $output);
    }

    #[Test]
    public function itColorsSummaryBoldForInfoOnly(): void
    {
        $ansiColor = new AnsiColor(true);
        $findings = [$this->createFinding(Severity::Info)];

        $report = new Report(
            findings: $findings,
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $ansiColor, $lines);

        $output = implode("\n", $lines);
        // Bold cyan for info only
        self::assertStringContainsString("\e[1;36m", $output);
    }

    private function createFinding(
        Severity $severity,
        string $namespace = 'App\\Service',
        string $class = 'Service',
    ): Finding {
        return self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Test violation',
            severity: $severity,
        );
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
