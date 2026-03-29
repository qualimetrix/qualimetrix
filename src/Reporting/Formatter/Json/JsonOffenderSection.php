<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Health\WorstOffender;
use Qualimetrix\Reporting\Report;

final class JsonOffenderSection
{
    public function __construct(
        private readonly NamespaceDrillDown $namespaceDrillDown,
        private readonly ViolationFilter $filter,
        private readonly JsonSanitizer $sanitizer,
    ) {}

    /**
     * Formats worst namespace offenders for JSON output.
     *
     * @param list<WorstOffender> $offenders
     *
     * @return list<array<string, mixed>>
     */
    public function formatNamespaces(
        array $offenders,
        FormatterContext $context,
        int $topN,
    ): array {
        $ranked = $this->rankOffenders($offenders, $context);

        return $this->formatWorstOffenders($ranked, $context, $topN, showClassCount: true);
    }

    /**
     * Resolves and formats worst class offenders for JSON output.
     *
     * For namespace drill-down, builds worst classes from metrics.
     * Otherwise, formats pre-computed worst offenders.
     *
     * @return list<array<string, mixed>>
     */
    public function formatClasses(
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
            $ranked = $this->rankOffenders($nsClasses, $context);
            $sliced = \array_slice($ranked, 0, $topN);

            $result = [];
            foreach ($sliced as $offender) {
                $result[] = [
                    'symbolPath' => $offender->symbolPath->toString(),
                    'healthOverall' => $this->sanitizer->sanitizeFloat($offender->healthOverall),
                    'label' => $offender->label,
                    'reason' => $offender->reason,
                    'violationCount' => $offender->violationCount,
                    'violationDensity' => $offender->violationDensity,
                    'file' => $offender->file !== null
                        ? $context->relativizePath($offender->file)
                        : null,
                    'metrics' => $this->sanitizer->sanitizeFloatArray($offender->metrics),
                    'healthScores' => $this->sanitizer->sanitizeFloatArray($offender->healthScores),
                ];
            }

            return $result;
        }

        $ranked = $this->rankOffenders($report->worstClasses, $context);

        return $this->formatWorstOffenders(
            $ranked,
            $context,
            $topN,
            showClassCount: false,
        );
    }

    /**
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
                'violationDensity' => $offender->violationDensity,
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
     * Re-ranks offenders when rank-by=density is requested.
     *
     * @param list<WorstOffender> $offenders
     *
     * @return list<WorstOffender>
     */
    private function rankOffenders(array $offenders, FormatterContext $context): array
    {
        return WorstOffender::rankByDensity($offenders, $context->getOption('rank-by', 'count'));
    }
}
