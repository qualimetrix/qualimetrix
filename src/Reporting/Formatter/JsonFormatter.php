<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\DecompositionItem;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\HealthScore;
use AiMessDetector\Reporting\NamespaceDrillDown;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\WorstOffender;
use Composer\InstalledVersions;

/**
 * Formats report as JSON with summary structure.
 *
 * Outputs health scores, worst offenders, and violations in a machine-readable
 * format suitable for AI agents, CI pipelines, and programmatic consumption.
 */
final class JsonFormatter implements FormatterInterface
{
    private const PACKAGE = 'aimd';
    private const DEFAULT_VIOLATION_LIMIT = 50;
    private const DEFAULT_TOP_OFFENDERS = 10;

    public function __construct(
        private readonly DebtCalculator $debtCalculator,
        private readonly NamespaceDrillDown $namespaceDrillDown,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $violations = $this->filterViolations($report->violations, $context);
        $filteredViolations = $this->sortViolations($violations);

        $limit = $this->getViolationLimit($context);
        $outputViolations = $limit === null
            ? $filteredViolations
            : \array_slice($filteredViolations, 0, $limit);

        $topN = $this->getTopN($context);

        // When drill-down is active, compute summary from filtered violations
        $isDrillDown = $context->namespace !== null || $context->class !== null;

        $data = [
            'meta' => [
                'version' => InstalledVersions::getRootPackage()['pretty_version'] ?? 'dev',
                'package' => self::PACKAGE,
                'timestamp' => gmdate('c'),
            ],
            'summary' => $this->buildSummary($report, $violations, $isDrillDown),
            'health' => $this->resolveAndFormatHealthScores($report, $context),
            'worstNamespaces' => $context->partialAnalysis ? [] : $this->formatWorstOffenders(
                $report->worstNamespaces,
                $context,
                $topN,
                showClassCount: true,
            ),
            'worstClasses' => $context->partialAnalysis ? [] : $this->resolveAndFormatWorstClasses(
                $report,
                $context,
                $topN,
            ),
            'violations' => array_map(
                fn(Violation $v): array => $this->formatViolation($v, $context),
                $outputViolations,
            ),
        ];

        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function getName(): string
    {
        return 'json';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * Builds the summary section.
     *
     * When drill-down is active, violation counts reflect the filtered set.
     *
     * @param list<Violation> $filteredViolations
     *
     * @return array<string, mixed>
     */
    private function buildSummary(Report $report, array $filteredViolations, bool $isDrillDown): array
    {
        if ($isDrillDown) {
            $errorCount = 0;
            $warningCount = 0;
            foreach ($filteredViolations as $v) {
                if ($v->severity === Severity::Error) {
                    $errorCount++;
                } else {
                    $warningCount++;
                }
            }

            $debtSummary = $this->debtCalculator->calculate($filteredViolations);

            return [
                'filesAnalyzed' => $report->filesAnalyzed,
                'filesSkipped' => $report->filesSkipped,
                'duration' => round($report->duration, 3),
                'violationCount' => \count($filteredViolations),
                'errorCount' => $errorCount,
                'warningCount' => $warningCount,
                'techDebtMinutes' => $debtSummary->totalMinutes,
            ];
        }

        return [
            'filesAnalyzed' => $report->filesAnalyzed,
            'filesSkipped' => $report->filesSkipped,
            'duration' => round($report->duration, 3),
            'violationCount' => $report->getTotalViolations(),
            'errorCount' => $report->errorCount,
            'warningCount' => $report->warningCount,
            'techDebtMinutes' => $report->techDebtMinutes,
        ];
    }

    /**
     * Resolves health scores (namespace-level when filtering) and formats for JSON.
     *
     * @return array<string, mixed>|null
     */
    private function resolveAndFormatHealthScores(Report $report, FormatterContext $context): ?array
    {
        if ($context->partialAnalysis) {
            return null;
        }

        $healthScores = $report->healthScores;

        if ($context->namespace !== null && $report->metrics !== null) {
            $nsScores = $this->namespaceDrillDown->buildSubtreeHealthScores($report->metrics, $context->namespace);
            if ($nsScores !== []) {
                $healthScores = $nsScores;
            }
        }

        return $this->formatHealthScores($healthScores, $context);
    }

    /**
     * Formats health scores for JSON output.
     *
     * Returns null for partial analysis (no health data) or empty health scores.
     * Always includes decomposition in JSON (unlike terminal where it's conditional).
     *
     * @param array<string, HealthScore> $healthScores
     *
     * @return array<string, mixed>|null
     */
    private function formatHealthScores(array $healthScores, FormatterContext $context): ?array
    {
        if ($context->partialAnalysis || $healthScores === []) {
            return null;
        }

        $result = [];
        foreach ($healthScores as $name => $hs) {
            $result[$name] = [
                'score' => $this->sanitizeFloat($hs->score),
                'label' => $hs->label,
                'threshold' => [
                    'warning' => $this->sanitizeFloat($hs->warningThreshold),
                    'error' => $this->sanitizeFloat($hs->errorThreshold),
                ],
                'decomposition' => array_map(
                    fn(DecompositionItem $item): array => [
                        'metric' => $item->metricKey,
                        'humanName' => $item->humanName,
                        'value' => $this->sanitizeFloat($item->value),
                        'good' => $item->goodValue,
                        'direction' => $item->direction,
                    ],
                    $hs->decomposition,
                ),
            ];
        }

        return $result;
    }

    /**
     * Resolves and formats worst classes: namespace-scoped when filtering.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveAndFormatWorstClasses(
        Report $report,
        FormatterContext $context,
        int $topN,
    ): array {
        if ($context->namespace !== null && $report->metrics !== null) {
            $nsClasses = $this->namespaceDrillDown->buildWorstClasses(
                $report->metrics,
                $context->namespace,
                $report->violations,
                includeNotableMetrics: true,
            );
            $sliced = \array_slice($nsClasses, 0, $topN);

            $result = [];
            foreach ($sliced as $offender) {
                $result[] = [
                    'symbolPath' => $offender->symbolPath->toString(),
                    'healthOverall' => $this->sanitizeFloat($offender->healthOverall),
                    'label' => $offender->label,
                    'reason' => $offender->reason,
                    'violationCount' => $offender->violationCount,
                    'file' => $offender->file !== null
                        ? $context->relativizePath($offender->file)
                        : null,
                    'metrics' => $this->sanitizeFloatArray($offender->metrics),
                    'healthScores' => $this->sanitizeFloatArray($offender->healthScores),
                ];
            }

            return $result;
        }

        return $this->formatWorstOffenders(
            $report->worstClasses,
            $context,
            $topN,
            showClassCount: false,
        );
    }

    /**
     * Formats and filters worst offenders.
     *
     * @param list<WorstOffender> $offenders
     *
     * @return list<array<string, mixed>>
     */
    private function formatWorstOffenders(
        array $offenders,
        FormatterContext $context,
        int $topN,
        bool $showClassCount,
    ): array {
        $filtered = $this->filterWorstOffenders($offenders, $context);
        $sliced = \array_slice($filtered, 0, $topN);

        $result = [];
        foreach ($sliced as $offender) {
            $entry = [
                'symbolPath' => $offender->symbolPath->toString(),
                'healthOverall' => $this->sanitizeFloat($offender->healthOverall),
                'label' => $offender->label,
                'reason' => $offender->reason,
                'violationCount' => $offender->violationCount,
            ];

            if ($showClassCount) {
                $entry['classCount'] = $offender->classCount;
            } else {
                $entry['file'] = $offender->file !== null
                    ? $context->relativizePath($offender->file)
                    : null;
                $entry['metrics'] = $this->sanitizeFloatArray($offender->metrics);
            }

            $entry['healthScores'] = $this->sanitizeFloatArray($offender->healthScores);

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Formats a single violation for JSON output.
     *
     * @return array<string, mixed>
     */
    private function formatViolation(Violation $violation, FormatterContext $context): array
    {
        $ns = $violation->symbolPath->namespace ?? '';
        $file = $violation->location->isNone()
            ? null
            : $context->relativizePath($violation->location->file);

        return [
            'file' => $file,
            'line' => $violation->location->line,
            'symbol' => $violation->symbolPath->toString(),
            'namespace' => $ns !== '' ? $ns : null,
            'rule' => $violation->ruleName,
            'code' => $violation->violationCode,
            'severity' => $violation->severity->value,
            'message' => $violation->getDisplayMessage(),
            'metricValue' => $this->sanitizeNumeric($violation->metricValue),
            'threshold' => $this->sanitizeNumeric($violation->threshold),
        ];
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

        return array_values(array_filter($offenders, function (WorstOffender $o) use ($context): bool {
            $name = $o->symbolPath->toString();

            if ($context->namespace !== null) {
                return $this->matchesNamespace($name, $context->namespace);
            }

            if ($context->class !== null) {
                return $name === $context->class;
            }

            return false;
        }));
    }

    /**
     * Sorts violations by severity (errors first), then by absolute threshold exceedance, then stable tie-breaker.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function sortViolations(array $violations): array
    {
        usort($violations, static function (Violation $a, Violation $b): int {
            // Errors before warnings
            $severityOrder = ($a->severity === Severity::Error ? 0 : 1) <=> ($b->severity === Severity::Error ? 0 : 1);
            if ($severityOrder !== 0) {
                return $severityOrder;
            }

            // Higher absolute exceedance first (when both have numeric values)
            $exceedA = self::getExceedance($a);
            $exceedB = self::getExceedance($b);
            $exceedOrder = $exceedB <=> $exceedA; // desc
            if ($exceedOrder !== 0) {
                return $exceedOrder;
            }

            // Stable tie-breaker: file, line, code
            return ($a->location->file <=> $b->location->file)
                ?: ($a->location->line <=> $b->location->line)
                ?: ($a->violationCode <=> $b->violationCode);
        });

        return $violations;
    }

    /**
     * Returns absolute distance between metric value and threshold.
     *
     * Returns 0.0 when either value is null (code smells without metrics).
     */
    private static function getExceedance(Violation $v): float
    {
        if ($v->metricValue === null || $v->threshold === null) {
            return 0.0;
        }

        $val = (float) $v->metricValue;
        $thr = (float) $v->threshold;

        if (!is_finite($val) || !is_finite($thr)) {
            return 0.0;
        }

        return abs($val - $thr);
    }

    /**
     * Returns the violation limit based on context.
     *
     * Priority: explicit --format-opt violations=N > --detail > default (50).
     * Returns null for "all violations" (no limit).
     */
    private function getViolationLimit(FormatterContext $context): ?int
    {
        $opt = $context->getOption('violations');

        if ($opt !== '') {
            if ($opt === 'all') {
                return null;
            }

            $parsed = filter_var($opt, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            return $parsed !== false ? $parsed : self::DEFAULT_VIOLATION_LIMIT;
        }

        // --detail implies all violations
        if ($context->detail) {
            return null;
        }

        return self::DEFAULT_VIOLATION_LIMIT;
    }

    /**
     * Returns the top-N limit for worst offenders.
     */
    private function getTopN(FormatterContext $context): int
    {
        $opt = $context->getOption('top');

        if ($opt !== '') {
            $parsed = filter_var($opt, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return $parsed !== false ? $parsed : self::DEFAULT_TOP_OFFENDERS;
        }

        return self::DEFAULT_TOP_OFFENDERS;
    }

    /**
     * Boundary-aware namespace prefix match.
     *
     * App\Payment matches App\Payment and App\Payment\Gateway but not App\PaymentGateway.
     */
    private function matchesNamespace(string $subject, string $prefix): bool
    {
        if ($subject === $prefix) {
            return true;
        }

        return str_starts_with($subject, $prefix . '\\');
    }

    /**
     * Sanitizes a float for JSON encoding (NaN/INF -> null).
     */
    private function sanitizeFloat(float $value): ?float
    {
        return is_finite($value) ? $value : null;
    }

    /**
     * Sanitizes a numeric value for JSON encoding (NaN/INF -> null).
     */
    private function sanitizeNumeric(int|float|null $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (\is_float($value) && !is_finite($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Sanitizes an array of float values for JSON encoding.
     *
     * @param array<string, int|float> $values
     *
     * @return array<string, int|float|null>
     */
    private function sanitizeFloatArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = $this->sanitizeNumeric($value);
        }

        return $result;
    }
}
