<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;

/** Evaluates the invocation's selected definitions over measured subjects. */
interface ComputedMetricEvaluatorInterface
{
    public function evaluate(MetricRepositoryInterface $repo, int $filesAnalyzed): ComputedMetricEvaluationSummary;
}
