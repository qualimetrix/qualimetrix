<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\AnsiColor;
use AiMessDetector\Reporting\Debt\DebtSummary;
use AiMessDetector\Reporting\DecompositionItem;
use AiMessDetector\Reporting\DetailedViolationRenderer;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\HealthScore;
use AiMessDetector\Reporting\NamespaceDrillDown;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\WorstOffender;

/**
 * Summary formatter -- default CLI output.
 *
 * Shows health overview, worst offenders, and contextual hints in one screen.
 * For detailed violation listing, use --format=text.
 */
final class SummaryFormatter implements FormatterInterface
{
    private const int MIN_BAR_WIDTH = 20;
    private const int DEFAULT_TERMINAL_WIDTH = 80;
    private const int MAX_WORST_OFFENDERS = 3;

    /** Default thresholds for worst offender score colorization (from health.overall defaults) */
    private const float OFFENDER_WARN_THRESHOLD = 50.0;
    private const float OFFENDER_ERR_THRESHOLD = 30.0;

    public function __construct(
        private readonly DetailedViolationRenderer $detailedRenderer,
        private readonly NamespaceDrillDown $namespaceDrillDown,
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
            $this->renderHealthScores($report, $context, $color, $terminalWidth, $ascii, $lines);
            $this->renderWorstNamespaces($report, $color, $context, $lines);
            $this->renderWorstClasses($report, $color, $context, $lines);
            $this->renderViolationSummary($report, $context, $color, $lines);
        }

        $this->renderHints($report, $context, $color, $lines);

        // Append detailed violation list when --detail is used
        if ($context->detail && !$report->isEmpty()) {
            $filteredViolations = $this->filterViolations($report->violations, $context);
            if ($filteredViolations !== []) {
                $lines[] = '';
                $lines[] = $color->bold('Violations');
                $lines[] = $this->detailedRenderer->render($filteredViolations, $context);
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
    private function renderHealthScores(
        Report $report,
        FormatterContext $context,
        AnsiColor $color,
        int $terminalWidth,
        bool $ascii,
        array &$lines,
    ): void {
        $healthScores = $this->resolveHealthScores($report, $context);

        if ($healthScores === []) {
            $lines[] = $color->dim('Health: insufficient data');
            $lines[] = '';

            return;
        }

        // Render overall first, then dimensions
        $overall = $healthScores['overall'] ?? null;
        $dimensions = array_filter(
            $healthScores,
            static fn(HealthScore $hs): bool => $hs->name !== 'overall',
        );

        $headerSuffix = '';
        if ($context->namespace !== null) {
            $headerSuffix = ' ' . $color->dim(\sprintf('[namespace: %s]', $context->namespace));
        }

        if ($overall !== null) {
            $lines[] = $color->bold('Health') . $headerSuffix . ' '
                . $this->renderHealthBar($overall->score, $overall->warningThreshold, $overall->errorThreshold, $terminalWidth, $ascii, $color)
                . ' ' . $this->formatScore($overall->score, $color, $overall->warningThreshold, $overall->errorThreshold)
                . ' ' . $color->dim($overall->label);
            $lines[] = '';
        }

        // Dynamic padding based on longest dimension name
        $padWidth = 0;
        foreach ($dimensions as $hs) {
            $padWidth = max($padWidth, \strlen(ucfirst($hs->name)));
        }
        $padWidth = max($padWidth, 10); // minimum padding
        $decompositionIndent = str_repeat(' ', $padWidth + 4); // 2 indent + padWidth + 2 space

        foreach ($dimensions as $hs) {
            $label = str_pad(ucfirst($hs->name), $padWidth);
            $scoreStr = $this->formatScore($hs->score, $color, $hs->warningThreshold, $hs->errorThreshold);

            if ($terminalWidth < self::DEFAULT_TERMINAL_WIDTH) {
                // Narrow terminal: no bars
                $lines[] = \sprintf('  %s %s %s', $label, $scoreStr, $color->dim($hs->label));
            } else {
                $bar = $this->renderHealthBar($hs->score, $hs->warningThreshold, $hs->errorThreshold, $terminalWidth, $ascii, $color);
                $lines[] = \sprintf('  %s %s %s %s', $label, $bar, $scoreStr, $color->dim($hs->label));
            }

            // Decomposition for dimensions needing attention
            foreach ($hs->decomposition as $item) {
                $lines[] = $this->renderDecompositionItem($item, $color, $decompositionIndent);
            }
        }

        $lines[] = '';
    }

    /**
     * Resolves health scores: namespace-level when filtering, project-level otherwise.
     *
     * @return array<string, HealthScore>
     */
    private function resolveHealthScores(Report $report, FormatterContext $context): array
    {
        if ($context->namespace === null || $report->metrics === null) {
            return $report->healthScores;
        }

        $nsScores = $this->namespaceDrillDown->buildSubtreeHealthScores($report->metrics, $context->namespace);

        return $nsScores !== [] ? $nsScores : $report->healthScores;
    }

    private function renderHealthBar(
        float $score,
        float $warnThreshold,
        float $errThreshold,
        int $terminalWidth,
        bool $ascii,
        AnsiColor $color,
    ): string {
        $barWidth = max(self::MIN_BAR_WIDTH, min(30, $terminalWidth - 50));
        $normalizedScore = (is_nan($score) || is_infinite($score)) ? 0.0 : $score;
        $filled = (int) round($normalizedScore / 100 * $barWidth);
        $filled = max(0, min($barWidth, $filled));
        $empty = $barWidth - $filled;

        if ($ascii) {
            $bar = str_repeat('#', $filled) . str_repeat('.', $empty);

            return $this->colorizeScore('[' . $bar . ']', $score, $warnThreshold, $errThreshold, $color);
        }

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        return $this->colorizeScore($bar, $score, $warnThreshold, $errThreshold, $color);
    }

    private function formatScore(float $score, AnsiColor $color, float $warnThreshold, float $errThreshold): string
    {
        $formatted = $this->formatValue($score) . '%';

        return $this->colorizeScore($formatted, $score, $warnThreshold, $errThreshold, $color);
    }

    private function colorizeScore(string $text, float $score, float $warnThreshold, float $errThreshold, AnsiColor $color): string
    {
        if ($score > $warnThreshold) {
            return $color->green($text);
        }

        if ($score > $errThreshold) {
            return $color->yellow($text);
        }

        return $color->red($text);
    }

    private function renderDecompositionItem(DecompositionItem $item, AnsiColor $color, string $indent): string
    {
        $value = $this->formatValue($item->value);
        $explanation = $item->explanation !== '' ? " — {$item->explanation}" : '';

        return \sprintf(
            '%s%s %s: %s (target: %s)%s',
            $indent,
            $color->dim('↳'),
            $item->humanName,
            $color->bold($value),
            $color->dim($item->goodValue),
            $color->dim($explanation),
        );
    }

    /**
     * @param list<string> $lines
     */
    private function renderWorstNamespaces(
        Report $report,
        AnsiColor $color,
        FormatterContext $context,
        array &$lines,
    ): void {
        $offenders = $this->filterWorstOffenders($report->worstNamespaces, $context);

        if ($offenders === []) {
            return;
        }

        // Skip namespace section for single-file analysis
        if ($report->filesAnalyzed <= 1) {
            return;
        }

        $lines[] = $color->bold('Worst namespaces');
        $this->renderOffenderList($offenders, $color, $lines, showClassCount: true);
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     */
    private function renderWorstClasses(
        Report $report,
        AnsiColor $color,
        FormatterContext $context,
        array &$lines,
    ): void {
        $offenders = $this->resolveWorstClasses($report, $context);

        if ($offenders === []) {
            return;
        }

        // Skip class section for single-file analysis
        if ($report->filesAnalyzed <= 1) {
            return;
        }

        $lines[] = $color->bold('Worst classes');
        $this->renderOffenderList($offenders, $color, $lines, showClassCount: false);
        $lines[] = '';
    }

    /**
     * Resolves worst classes: builds from namespace metrics when filtering, otherwise uses pre-built list.
     *
     * @return list<WorstOffender>
     */
    private function resolveWorstClasses(Report $report, FormatterContext $context): array
    {
        if ($context->namespace !== null && $report->metrics !== null) {
            return $this->namespaceDrillDown->buildWorstClasses($report->metrics, $context->namespace, $report->violations);
        }

        return $this->filterWorstOffenders($report->worstClasses, $context);
    }

    /**
     * Renders truncated offender list with "+N more" indicator.
     *
     * @param list<WorstOffender> $offenders
     * @param list<string> $lines
     */
    private function renderOffenderList(array $offenders, AnsiColor $color, array &$lines, bool $showClassCount): void
    {
        foreach (\array_slice($offenders, 0, self::MAX_WORST_OFFENDERS) as $offender) {
            $this->renderWorstOffender($offender, $color, $lines, $showClassCount);
        }

        $remaining = \count($offenders) - self::MAX_WORST_OFFENDERS;
        if ($remaining > 0) {
            $lines[] = $color->dim(\sprintf('  +%d more', $remaining));
        }
    }

    /**
     * @param list<string> $lines
     */
    private function renderWorstOffender(
        WorstOffender $offender,
        AnsiColor $color,
        array &$lines,
        bool $showClassCount,
    ): void {
        $name = $offender->symbolPath->toString();
        $scoreText = $this->formatValue($offender->healthOverall);

        $meta = [];
        if ($showClassCount && $offender->classCount > 0) {
            $meta[] = \sprintf('%d classes', $offender->classCount);
        }
        if ($offender->violationCount > 0) {
            $meta[] = \sprintf('%d violations', $offender->violationCount);
        }

        $metaStr = $meta !== [] ? $color->dim(' (' . implode(', ', $meta) . ')') : '';
        $reasonStr = $offender->reason !== '' ? $color->dim(" — {$offender->reason}") : '';

        $lines[] = \sprintf(
            '  %s %s%s%s',
            $this->colorizeScore($scoreText, $offender->healthOverall, self::OFFENDER_WARN_THRESHOLD, self::OFFENDER_ERR_THRESHOLD, $color),
            $color->bold($name),
            $metaStr,
            $reasonStr,
        );
    }

    /**
     * @param list<string> $lines
     */
    private function renderViolationSummary(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $violations = $this->filterViolations($report->violations, $context);

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

        if ($report->techDebtMinutes > 0 && $context->namespace === null && $context->class === null) {
            $parts[] = \sprintf('Tech debt: %s', DebtSummary::formatMinutes($report->techDebtMinutes));
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

        if (!$report->isEmpty() && !$context->detail) {
            $hints[] = '--detail to see all violations';
        }

        if ($context->partialAnalysis) {
            $hints[] = 'run full analysis for project health overview';
        }

        if ($report->healthScores !== [] && $context->namespace === null && $context->class === null) {
            // Suggest drill-down into worst namespace
            $worstNs = $report->worstNamespaces[0] ?? null;
            if ($worstNs !== null) {
                $nsName = $this->escapeForShell($worstNs->symbolPath->toString());
                $hints[] = \sprintf('--namespace=%s to drill down', $nsName);
            }
        }

        $hints[] = '--format=html -o report.html for full report';

        $lines[] = $color->dim('Hints: ' . implode(' | ', $hints));
    }

    /**
     * Filters violations by namespace/class context.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function filterViolations(array $violations, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $violations;
        }

        return array_values(array_filter($violations, function (Violation $v) use ($context): bool {
            $ns = $v->symbolPath->namespace ?? '';
            $class = $v->symbolPath->type;

            if ($context->namespace !== null) {
                // Match violations whose namespace is within the filter prefix
                return $this->matchesNamespace($ns, $context->namespace);
            }

            if ($context->class !== null && $class !== null) {
                $fqcn = $ns !== '' ? $ns . '\\' . $class : $class;

                return $fqcn === $context->class;
            }

            return false;
        }));
    }

    /**
     * Filters worst offenders by namespace/class context.
     *
     * @param list<WorstOffender> $offenders
     *
     * @return list<WorstOffender>
     */
    private function filterWorstOffenders(array $offenders, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $offenders;
        }

        return array_values(array_filter($offenders, function (WorstOffender $offender) use ($context): bool {
            $canonical = $offender->symbolPath->toString();

            if ($context->namespace !== null) {
                return $this->matchesNamespace($canonical, $context->namespace);
            }

            if ($context->class !== null) {
                return $canonical === $context->class;
            }

            return true;
        }));
    }

    /**
     * Boundary-aware namespace prefix match.
     *
     * App\Payment matches App\Payment\Gateway but not App\PaymentGateway.
     */
    private function matchesNamespace(string $subject, string $prefix): bool
    {
        if ($subject === $prefix) {
            return true;
        }

        return str_starts_with($subject, $prefix . '\\');
    }

    private function formatValue(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return '—';
        }

        if ($value === floor($value) && abs($value) < 1e12) {
            return (string) (int) $value;
        }

        return \sprintf('%.1f', $value);
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
