<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter\Json;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Filter\ViolationFilter;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;
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
        private readonly ViolationFilter $filter,
        private readonly JsonHealthSection $healthSection,
        private readonly JsonOffenderSection $offenderSection,
        private readonly JsonViolationSection $violationSection,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $violations = $this->filter->filterViolations($report->violations, $context);
        $filteredViolations = $this->violationSection->sort($violations);

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
            'health' => $this->healthSection->format($report, $context),
            'worstNamespaces' => $context->partialAnalysis ? [] : $this->offenderSection->formatNamespaces(
                $report->worstNamespaces,
                $context,
                $topN,
            ),
            'worstClasses' => $context->partialAnalysis ? [] : $this->offenderSection->formatClasses(
                $report,
                $context,
                $topN,
            ),
            'violations' => $this->violationSection->format($outputViolations, $context),
            'violationsMeta' => [
                'total' => \count($filteredViolations),
                'shown' => \count($outputViolations),
                'limit' => $limit,
                'truncated' => $limit !== null && \count($filteredViolations) > $limit,
                'byRule' => $this->violationSection->countByRule($filteredViolations),
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
