<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\OffenderNamespaceSelection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\Formatter\FormatOptionValue;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

final class JsonOffenderSection
{
    public function __construct(
        private readonly WorstClassDrillDown $namespaceDrillDown,
        private readonly FindingFilter $filter,
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
        $selected = $this->filter->filterWorstOffenders($offenders, $context);

        return $this->formatWorstOffenders($selected, $context, $topN, showClassCount: true);
    }

    /**
     * Resolves and formats worst class offenders for JSON output.
     *
     * Selects from the complete report population before ranking and truncation.
     *
     * @return list<array<string, mixed>>
     */
    public function formatClasses(
        Report $report,
        FormatterContext $context,
        int $topN,
    ): array {
        $selected = $context->namespace !== null
            ? $this->namespaceDrillDown->buildWorstClasses(
                $report->worstClasses,
                new OffenderNamespaceSelection([$context->namespace]),
            )
            : $this->filter->filterWorstOffenders($report->worstClasses, $context);

        return $this->formatWorstOffenders($selected, $context, $topN, showClassCount: false);
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
        $ranked = $this->rankOffenders($offenders, $context);
        $sliced = \array_slice($ranked, 0, $topN);

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
                $entry['size.class-count.sum'] = $offender->classCount;
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
     * Ranks selected offenders by the requested score or density.
     *
     * @param list<WorstOffender> $offenders
     *
     * @return list<WorstOffender>
     */
    private function rankOffenders(array $offenders, FormatterContext $context): array
    {
        return WorstOffender::rank($offenders, FormatOptionValue::rankBy($context->getOption('rank-by', 'score')));
    }
}
