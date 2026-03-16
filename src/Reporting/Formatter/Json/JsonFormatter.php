<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter\Json;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\DecompositionItem;
use AiMessDetector\Reporting\Filter\ViolationFilter;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Health\HealthScoreResolver;
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
        private readonly RemediationTimeRegistry $remediationTimeRegistry,
        private readonly ViolationFilter $filter,
        private readonly HealthScoreResolver $healthResolver,
        private readonly JsonSanitizer $sanitizer,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $violations = $this->filter->filterViolations($report->violations, $context);
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
            'summary' => $this->buildSummary($report, $filteredViolations, $isDrillDown),
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
            'violationsMeta' => [
                'total' => \count($filteredViolations),
                'shown' => \count($outputViolations),
                'limit' => $limit,
                'truncated' => $limit !== null && \count($filteredViolations) > $limit,
                'byRule' => $this->countByRule($filteredViolations),
            ],
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
            'debtPer1kLoc' => $report->debtPer1kLoc,
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

        $healthScores = $this->healthResolver->resolve($report, $context);
        if ($healthScores === []) {
            // For namespace drill-down that returns empty, this means no health data
            if ($context->namespace !== null) {
                return null;
            }
        }

        // C2: Include direct (flat) score for transparency (namespace drill-down only)
        if ($context->namespace !== null && $report->metrics !== null) {
            $nsPath = SymbolPath::forNamespace($context->namespace);
            $flatOverall = $report->metrics->get($nsPath)->get('health.overall');
            $result = $this->formatHealthScores($healthScores, $context);
            if ($result !== null && $flatOverall !== null) {
                $recursiveScore = $result['overall']['score'] ?? null;
                $flatScore = $this->sanitizer->sanitizeFloat((float) $flatOverall);
                if ($recursiveScore !== null && abs($recursiveScore - $flatScore) > 5.0) {
                    $result['overall']['scope'] = 'recursive';
                    $result['overall']['directScore'] = $flatScore;
                }
            }

            return $result;
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
                'score' => $hs->score !== null ? $this->sanitizer->sanitizeFloat($hs->score) : null,
                'label' => $hs->label,
                'threshold' => [
                    'warning' => $this->sanitizer->sanitizeFloat($hs->warningThreshold),
                    'error' => $this->sanitizer->sanitizeFloat($hs->errorThreshold),
                ],
                'decomposition' => array_map(
                    fn(DecompositionItem $item): array => [
                        'metric' => $item->metricKey,
                        'humanName' => $item->humanName,
                        'value' => $this->sanitizer->sanitizeFloat($item->value),
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
                    'healthOverall' => $this->sanitizer->sanitizeFloat($offender->healthOverall),
                    'label' => $offender->label,
                    'reason' => $offender->reason,
                    'violationCount' => $offender->violationCount,
                    'file' => $offender->file !== null
                        ? $context->relativizePath($offender->file)
                        : null,
                    'metrics' => $this->sanitizer->sanitizeFloatArray($offender->metrics),
                    'healthScores' => $this->sanitizer->sanitizeFloatArray($offender->healthScores),
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
        $filtered = $this->filter->filterWorstOffenders($offenders, $context);
        $sliced = \array_slice($filtered, 0, $topN);

        $result = [];
        foreach ($sliced as $offender) {
            $entry = [
                'symbolPath' => $offender->symbolPath->toString(),
                'healthOverall' => $this->sanitizer->sanitizeFloat($offender->healthOverall),
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
                $entry['metrics'] = $this->sanitizer->sanitizeFloatArray($offender->metrics);
            }

            $entry['healthScores'] = $this->sanitizer->sanitizeFloatArray($offender->healthScores);

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
            'message' => $violation->message,
            'recommendation' => $violation->recommendation,
            'metricValue' => $this->sanitizer->sanitizeNumeric($violation->metricValue),
            'threshold' => $this->sanitizer->sanitizeNumeric($violation->threshold),
            'techDebtMinutes' => $this->remediationTimeRegistry->getMinutesForViolation($violation),
        ];
    }

    /**
     * Counts violations by rule name.
     *
     * @param list<Violation> $violations
     *
     * @return array<string, int>
     */
    private function countByRule(array $violations): array
    {
        $counts = [];

        foreach ($violations as $violation) {
            $rule = $violation->ruleName;
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
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
        // Support both --format-opt=violations=N and --format-opt=limit=N
        // "violations" takes precedence when both are set
        $opt = $context->getOption('violations');
        $isLimitAlias = false;

        if ($opt === '') {
            $opt = $context->getOption('limit');
            $isLimitAlias = $opt !== '';
        }

        if ($opt !== '') {
            if ($opt === 'all') {
                return null;
            }

            $parsed = filter_var($opt, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($parsed === false) {
                return self::DEFAULT_VIOLATION_LIMIT;
            }

            // limit=0 means "no limit" (show all), violations=0 means "show none"
            if ($isLimitAlias && $parsed === 0) {
                return null;
            }

            return $parsed;
        }

        // --detail mode: respect limit (0 = all)
        if ($context->isDetailEnabled()) {
            return $context->detailLimit === 0 ? null : $context->detailLimit;
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
}
