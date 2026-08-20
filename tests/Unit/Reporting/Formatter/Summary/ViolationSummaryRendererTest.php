<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Summary\ViolationSummaryRenderer;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

#[CoversClass(ViolationSummaryRenderer::class)]
final class ViolationSummaryRendererTest extends TestCase
{
    private ViolationSummaryRenderer $renderer;
    private AnsiColor $color;

    protected function setUp(): void
    {
        $this->renderer = new ViolationSummaryRenderer(
            new ViolationFilter(),
            new RemediationTimeRegistry(),
        );
        $this->color = new AnsiColor(false);
    }

    #[Test]
    public function itShowsNoViolationsFoundForEmptyReport(): void
    {
        $report = new Report(
            violations: [],
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
    public function itShowsNoViolationsInNamespaceScope(): void
    {
        // Report has violations in a different namespace so isEmpty() is false,
        // but the filtered violations for this namespace are empty.
        $otherViolation = $this->createViolation(Severity::Error, 'Other\\Namespace', 'OtherService');

        $report = new Report(
            violations: [$otherViolation],
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
    public function itShowsNoViolationsInClassScope(): void
    {
        $otherViolation = $this->createViolation(Severity::Error, 'App\\Service', 'OtherService');

        $report = new Report(
            violations: [$otherViolation],
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
    public function itShowsNoViolationsFoundForNonEmptyReportWithNoScope(): void
    {
        // Non-empty report (filesAnalyzed > 0) with no violations and no scope filter
        // isEmpty() returns true => shows "No violations found."
        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
        );

        $lines = [];
        $this->renderer->render($report, new FormatterContext(), $this->color, $lines);

        // isEmpty() is true, so "No violations found." is shown
        self::assertSame(['No violations found.', ''], $lines);
    }

    #[Test]
    public function itFormatsSingleError(): void
    {
        $violation = $this->createViolation(Severity::Error);

        $report = new Report(
            violations: [$violation],
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
        $violation = $this->createViolation(Severity::Warning);

        $report = new Report(
            violations: [$violation],
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
        $violation = $this->createViolation(Severity::Info);

        $report = new Report(
            violations: [$violation],
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
        $violations = [
            $this->createViolation(Severity::Error),
            $this->createViolation(Severity::Error),
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Warning),
        ];

        $report = new Report(
            violations: $violations,
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
        $violations = [$this->createViolation(Severity::Error)];

        $report = new Report(
            violations: $violations,
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
        $violations = [$this->createViolation(Severity::Error)];

        $report = new Report(
            violations: $violations,
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
        $violations = [$this->createViolation(Severity::Warning)];

        $report = new Report(
            violations: $violations,
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
        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App\\Service', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'CCN is 30',
            severity: Severity::Error,
            metricValue: 30,
            threshold: 10,
        );

        $report = new Report(
            violations: [$violation],
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
        // Scoped debt is calculated from the violation, not from report total
        self::assertStringContainsString('Tech debt:', $output);
    }

    #[Test]
    public function itColorsSummaryBoldForErrors(): void
    {
        $ansiColor = new AnsiColor(true);
        $violations = [$this->createViolation(Severity::Error)];

        $report = new Report(
            violations: $violations,
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
        $violations = [$this->createViolation(Severity::Warning)];

        $report = new Report(
            violations: $violations,
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
        $violations = [$this->createViolation(Severity::Info)];

        $report = new Report(
            violations: $violations,
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

    private function createViolation(
        Severity $severity,
        string $namespace = 'App\\Service',
        string $class = 'Service',
    ): Violation {
        return self::violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Test violation',
            severity: $severity,
        );
    }

    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations */
    private static function violation(\Qualimetrix\Analysis\Finding\Contract\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $violationCode, string $message, \Qualimetrix\Analysis\Finding\Contract\Severity $severity, int|float|null $metricValue = null, ?\Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel $level = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null, ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Violation
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };
        return new Violation(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, violationCode: $violationCode, message: $message, severity: $severity, metricValue: $metricValue, level: $level, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}
