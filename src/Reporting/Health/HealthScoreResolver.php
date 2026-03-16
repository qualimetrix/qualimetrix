<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Health;

use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\HealthScore;
use AiMessDetector\Reporting\NamespaceDrillDown;
use AiMessDetector\Reporting\Report;

/**
 * Resolves health scores based on context (project/namespace/class level).
 *
 * Shared between SummaryFormatter and JsonFormatter to avoid duplication.
 */
final class HealthScoreResolver
{
    public function __construct(
        private readonly NamespaceDrillDown $namespaceDrillDown,
    ) {}

    /**
     * Resolves health scores: namespace-level when filtering, project-level otherwise.
     *
     * @return array<string, HealthScore>
     */
    public function resolve(Report $report, FormatterContext $context): array
    {
        if ($report->metrics === null) {
            return $report->healthScores;
        }

        if ($context->class !== null) {
            $classScores = $this->namespaceDrillDown->buildClassHealthScores($report->metrics, $context->class);

            return $classScores !== [] ? $classScores : $report->healthScores;
        }

        if ($context->namespace !== null) {
            $nsScores = $this->namespaceDrillDown->buildSubtreeHealthScores($report->metrics, $context->namespace);

            return $nsScores !== [] ? $nsScores : [];
        }

        return $report->healthScores;
    }
}
