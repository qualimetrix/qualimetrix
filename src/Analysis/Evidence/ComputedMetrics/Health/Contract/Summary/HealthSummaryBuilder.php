<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\OffenderRanking;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\OffenderThresholds;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\ProjectHealthScoreBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;

final readonly class HealthSummaryBuilder
{
    private ProjectHealthScoreBuilder $projectScores;

    public function __construct(
        HealthMetricCatalog $hintProvider,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private OffenderRanking $ranking = new OffenderRanking(),
    ) {
        $this->projectScores = new ProjectHealthScoreBuilder($hintProvider);
    }

    /** @param list<Finding> $findings */
    public function build(MetricRepositoryInterface $metrics, NamespaceTree $tree, array $findings): HealthSummary
    {
        $thresholds = OffenderThresholds::fromCatalog($this->definitionCatalog);
        $healthScores = $this->projectScores->build($metrics, $thresholds);
        $ranked = $this->ranking->rank($metrics, $tree, $findings, $thresholds);

        return new HealthSummary($healthScores, $ranked->namespaces, $ranked->classes);
    }
}
