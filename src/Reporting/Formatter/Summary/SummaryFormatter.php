<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter\Summary;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtSummary;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Filter\ViolationFilter;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\Formatter\Support\AnsiColor;
use AiMessDetector\Reporting\Formatter\Support\DetailedViolationRenderer;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

/**
 * Summary formatter -- default CLI output.
 *
 * Shows health overview, worst offenders, and contextual hints in one screen.
 * For detailed violation listing, use --format=text.
 */
final class SummaryFormatter implements FormatterInterface
{
    private const int DEFAULT_TERMINAL_WIDTH = 80;

    public function __construct(
        private readonly DetailedViolationRenderer $detailedRenderer,
        private readonly HealthBarRenderer $healthBarRenderer,
        private readonly OffenderListRenderer $offenderListRenderer,
        private readonly ViolationFilter $violationFilter,
        private readonly RemediationTimeRegistry $remediationTimeRegistry,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $terminalWidth = $context->terminalWidth > 0 ? $context->terminalWidth : self::DEFAULT_TERMINAL_WIDTH;
        $ascii = (bool) getenv('AIMD_ASCII');
        $lines = [];

        $this->renderHeader($report, $context, $color, $lines);

        if ($context->partialAnalysis) {
            $this->renderPartialAnalysisWarning($color, $lines);
            $this->renderViolationSummary($report, $context, $color, $lines);
        } else {
            $this->healthBarRenderer->render($report, $context, $color, $terminalWidth, $ascii, $lines);
            $this->offenderListRenderer->renderWorstNamespaces($report, $color, $context, $lines);
            $this->offenderListRenderer->renderWorstClasses($report, $color, $context, $lines);
            $this->renderViolationSummary($report, $context, $color, $lines);
        }

        $this->renderHints($report, $context, $color, $lines);

        // Append detailed violation list when --detail is used
        if ($context->isDetailEnabled() && !$report->isEmpty()) {
            $filteredViolations = $this->violationFilter->filterViolations($report->violations, $context);
            if ($filteredViolations !== []) {
                $limit = $context->detailLimit;
                $totalCount = \count($filteredViolations);
                $showAll = $limit === null || $limit === 0 || $totalCount <= $limit;
                $displayViolations = $showAll ? $filteredViolations : \array_slice($filteredViolations, 0, $limit);

                $lines[] = '';
                $lines[] = $color->bold('Violations');
                $lines[] = $this->detailedRenderer->render($displayViolations, $context);

                if (!$showAll) {
                    $remaining = $totalCount - $limit;
                    $lines[] = '';
                    $lines[] = $color->dim(\sprintf(
                        '... and %d more. Use --detail=all to see all violations',
                        $remaining,
                    ));
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'summary';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * @param list<string> $lines
     */
    private function renderHeader(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $header = \sprintf(
            'AI Mess Detector — %d file%s analyzed',
            $report->filesAnalyzed,
            $report->filesAnalyzed === 1 ? '' : 's',
        );

        if ($context->partialAnalysis) {
            $header .= ' (partial)';
        }

        if ($context->namespace !== null) {
            $header .= \sprintf(' [namespace: %s]', $context->namespace);
        } elseif ($context->class !== null) {
            $header .= \sprintf(' [class: %s]', $context->class);
        }

        $header .= \sprintf(', %.1fs', $report->duration);

        $lines[] = $color->bold($header);
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     */
    private function renderPartialAnalysisWarning(AnsiColor $color, array &$lines): void
    {
        $lines[] = $color->yellow('⚠ Health scores unavailable in partial analysis mode');
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     */
    private function renderViolationSummary(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $violations = $this->violationFilter->filterViolations($report->violations, $context);

        if ($violations === []) {
            if ($report->isEmpty()) {
                $lines[] = $color->boldGreen('No violations found.');
            } elseif ($context->namespace !== null || $context->class !== null) {
                $lines[] = $color->boldGreen('No violations in this scope.');
            }
            $lines[] = '';

            return;
        }

        $total = \count($violations);
        $errors = 0;
        $warnings = 0;
        foreach ($violations as $v) {
            if ($v->severity === Severity::Error) {
                $errors++;
            } else {
                $warnings++;
            }
        }

        $parts = [];
        $parts[] = \sprintf('%d violation%s', $total, $total === 1 ? '' : 's');

        $details = [];
        if ($errors > 0) {
            $details[] = \sprintf('%d error%s', $errors, $errors === 1 ? '' : 's');
        }
        if ($warnings > 0) {
            $details[] = \sprintf('%d warning%s', $warnings, $warnings === 1 ? '' : 's');
        }
        if ($details !== []) {
            $parts[0] .= ' (' . implode(', ', $details) . ')';
        }

        if ($context->namespace === null && $context->class === null) {
            if ($report->techDebtMinutes > 0) {
                $debtStr = DebtSummary::formatMinutes($report->techDebtMinutes);
                if ($report->debtPer1kLoc !== null) {
                    $debtStr .= \sprintf(' (%.1f min/kLOC to fix)', $report->debtPer1kLoc);
                }
                $parts[] = \sprintf('Tech debt: %s', $debtStr);
            }
        } else {
            $scopedDebtMinutes = $this->calculateScopedDebt($violations);
            if ($scopedDebtMinutes > 0) {
                $parts[] = \sprintf('Tech debt: %s', DebtSummary::formatMinutes($scopedDebtMinutes));
            }
        }

        $summary = implode(' | ', $parts);

        if ($errors > 0) {
            $lines[] = $color->boldRed($summary);
        } elseif ($warnings > 0) {
            $lines[] = $color->boldYellow($summary);
        } else {
            $lines[] = $color->boldGreen($summary);
        }

        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     */
    private function renderHints(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $hints = [];

        if (!$report->isEmpty() && !$context->isDetailEnabled()) {
            $hints[] = '--detail to see violations (top 200)';
        }

        if ($context->partialAnalysis) {
            $hints[] = 'run full analysis for project health overview';
        }

        if ($report->healthScores !== [] && $context->class === null) {
            if ($context->namespace !== null) {
                // In namespace drill-down: suggest --class for worst class
                $worstClasses = $this->offenderListRenderer->resolveWorstClasses($report, $context);
                $worstCls = $worstClasses[0] ?? null;
                if ($worstCls !== null) {
                    $clsName = $this->escapeForShell($worstCls->symbolPath->toString());
                    $hints[] = \sprintf('--class=%s to drill deeper', $clsName);
                }
            } else {
                // At project level: suggest --namespace for worst namespace
                $worstNs = $report->worstNamespaces[0] ?? null;
                if ($worstNs !== null) {
                    $nsName = $this->escapeForShell($worstNs->symbolPath->toString());
                    $hints[] = \sprintf('--namespace=%s to drill down', $nsName);
                }
            }
        }

        $hints[] = '--format=html -o report.html for full report';

        $lines[] = $color->dim('Hints: ' . implode(' | ', $hints));
    }

    /**
     * Calculates total tech debt for scoped (namespace/class) violations.
     *
     * @param list<Violation> $violations Already filtered violations
     */
    private function calculateScopedDebt(array $violations): int
    {
        $totalMinutes = 0;

        foreach ($violations as $violation) {
            $totalMinutes += $this->remediationTimeRegistry->getMinutesForViolation($violation);
        }

        return $totalMinutes;
    }

    private function escapeForShell(string $value): string
    {
        // Single quotes prevent shell interpretation of backslashes in namespaces
        if (str_contains($value, '\\')) {
            return "'" . $value . "'";
        }

        return $value;
    }
}
