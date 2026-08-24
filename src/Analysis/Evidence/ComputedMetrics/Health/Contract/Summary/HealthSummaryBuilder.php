<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\ContributorRanker;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Builds health scores and offender projections from measured evidence.
 */
final readonly class HealthSummaryBuilder
{
    private const int DEFAULT_TOP_NAMESPACES = 10;
    private const int DEFAULT_TOP_CLASSES = 10;

    private ContributorRanker $contributorRanker;
    private HealthDimensionCatalog $dimensions;
    private WorstOffenderBuilder $offenderBuilder;

    public function __construct(
        private HealthMetricCatalog $hintProvider,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {
        $this->contributorRanker = new ContributorRanker();
        $this->dimensions = new HealthDimensionCatalog();
        $this->offenderBuilder = new WorstOffenderBuilder();
    }

    /** @param list<Finding> $findings */
    public function build(
        MetricRepositoryInterface $metrics,
        NamespaceTree $tree,
        array $findings,
    ): HealthSummary {
        $healthScores = $this->buildHealthScores($metrics);
        $worstNamespaces = $this->buildWorstOffenders($metrics, $findings, SymbolType::Namespace_, self::DEFAULT_TOP_NAMESPACES, $tree);
        $worstClasses = $this->buildWorstOffenders($metrics, $findings, SymbolType::Class_, self::DEFAULT_TOP_CLASSES, $tree);

        return new HealthSummary(
            healthScores: $healthScores,
            worstNamespaces: $worstNamespaces,
            worstClasses: $worstClasses,
        );
    }

    /**
     * @return array<string, HealthScore>
     */
    private function buildHealthScores(MetricRepositoryInterface $metrics): array
    {
        $projectMetrics = $metrics->get(SymbolPath::forProject());
        $healthScores = [];

        foreach (HealthDimension::all() as $dim) {
            $score = $projectMetrics->get($dim->value);

            if ($score === null) {
                continue;
            }

            $scoreValue = (float) $score;
            [$warnThreshold, $errThreshold] = $this->thresholds($dim);

            $decomposition = $this->buildDecomposition($dim->value, $projectMetrics);
            $inputs = $this->hintProvider->getDecompositionForClasses($dim->value);
            $contributors = $inputs === []
                ? []
                : $this->contributorRanker->rank(
                    array_map(function ($symbol) use ($metrics, $inputs): array {
                        $classMetrics = $metrics->get($symbol->symbolPath);
                        $selection = $this->dimensions->selectContributorMetrics($inputs, $classMetrics->get(...));

                        return [
                            'symbol' => $symbol,
                            'primaryValue' => $selection['primaryValue'],
                            'contributorMetrics' => $selection['contributorMetrics'],
                        ];
                    }, iterator_to_array($metrics->all(SymbolType::Class_), false)),
                    $inputs[0]['direction'],
                );

            $dimensionName = $dim->shortName();
            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $scoreValue,
                label: $this->hintProvider->getScoreLabel($scoreValue, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                decomposition: $decomposition,
                worstContributors: $contributors,
            );
        }

        // Show typing dimension with "0 classes analyzed" when other dimensions exist but typing doesn't,
        // unless typing was explicitly excluded via --exclude-health
        if ($healthScores !== [] && !isset($healthScores['typing']) && !$this->isDefinitionExcluded(HealthDimension::Typing->value)) {
            [$typingWarning, $typingError] = $this->thresholds(HealthDimension::Typing);
            $healthScores['typing'] = new HealthScore(
                name: 'typing',
                score: null,
                label: '0 classes analyzed',
                warningThreshold: $typingWarning,
                errorThreshold: $typingError,
            );
        }

        return $healthScores;
    }

    /**
     * Builds decomposition items for a health dimension.
     *
     * Always returns the contributing metrics regardless of score value,
     * so that JSON consumers can inspect what feeds into each dimension.
     *
     * @return list<DecompositionItem>
     */
    private function buildDecomposition(
        string $dimension,
        MetricBag $projectMetrics,
    ): array {
        // Typing dimension needs special handling: compute percentages from raw sums
        if ($dimension === HealthDimension::Typing->value) {
            return $this->buildTypingDecomposition($projectMetrics);
        }

        $metricKeys = $this->hintProvider->getDecomposition($dimension);
        $items = [];

        foreach ($metricKeys as $metricKey) {
            $value = $projectMetrics->get($metricKey);

            if ($value === null) {
                continue;
            }

            $floatValue = (float) $value;
            $label = $this->hintProvider->getLabel($metricKey) ?? $metricKey;
            $goodValue = $this->hintProvider->getGoodValue($metricKey) ?? '';
            $direction = $this->hintProvider->getDirection($metricKey) ?? 'lower_is_better';
            $explanation = $this->hintProvider->getExplanation($metricKey, $floatValue);

            $items[] = new DecompositionItem(
                metricKey: $metricKey,
                humanName: $label,
                value: $floatValue,
                goodValue: $goodValue,
                direction: $direction,
                explanation: $explanation,
            );
        }

        return $items;
    }

    /**
     * @return list<DecompositionItem>
     */
    private function buildTypingDecomposition(MetricBag $metrics): array
    {
        $components = [
            ['label' => 'Parameter types', 'typed' => MetricName::agg(MetricName::TYPE_COVERAGE_PARAM_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::TYPE_COVERAGE_PARAM_TOTAL, AggregationStrategy::Sum)],
            ['label' => 'Return types', 'typed' => MetricName::agg(MetricName::TYPE_COVERAGE_RETURN_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::TYPE_COVERAGE_RETURN_TOTAL, AggregationStrategy::Sum)],
            ['label' => 'Property types', 'typed' => MetricName::agg(MetricName::TYPE_COVERAGE_PROPERTY_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL, AggregationStrategy::Sum)],
        ];

        $items = [];

        foreach ($components as $component) {
            $typed = $metrics->get($component['typed']);
            $total = $metrics->get($component['total']);

            if ($total === null || (int) $total === 0) {
                continue;
            }

            $pct = round((float) $typed / (float) $total * 100, 1);

            $items[] = new DecompositionItem(
                metricKey: $component['typed'],
                humanName: $component['label'],
                value: $pct,
                goodValue: '100%',
                direction: 'higher_is_better',
                explanation: \sprintf('%d of %d typed (%.1f%%)', (int) $typed, (int) $total, $pct),
            );
        }

        return $items;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<WorstOffender>
     */
    private function buildWorstOffenders(
        MetricRepositoryInterface $repository,
        array $findings,
        SymbolType $symbolType,
        int $limit,
        NamespaceTree $tree,
    ): array {
        [$warnThreshold, $errorThreshold] = $this->thresholds(HealthDimension::Overall);

        /** @var list<array{score: float, info: \Qualimetrix\Core\Symbol\SymbolInfo}> $candidates */
        $candidates = [];

        foreach ($repository->all($symbolType) as $symbolInfo) {
            $metrics = $repository->get($symbolInfo->symbolPath);
            $healthOverall = $metrics->get(HealthDimension::Overall->value);

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;

            // Skip namespaces with no direct classes (e.g., root namespace containers like "PHPUnit")
            if ($symbolType === SymbolType::Namespace_) {
                $classCountInNs = (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0);

                if ($classCountInNs === 0) {
                    continue;
                }
            }

            $candidates[] = ['score' => $scoreValue, 'info' => $symbolInfo];
        }

        // Sort by score ascending (worst first), with stable secondary sort by canonical path
        usort($candidates, static fn(array $a, array $b): int => ($a['score'] <=> $b['score']) !== 0 ? ($a['score'] <=> $b['score'])
                : ($a['info']->symbolPath->toCanonical() <=> $b['info']->symbolPath->toCanonical()));

        $violationCounts = $this->countFindingsPerSymbol($findings, $symbolType, $tree);

        $offenders = [];

        foreach (\array_slice($candidates, 0, $limit) as $candidate) {
            $symbolInfo = $candidate['info'];
            $metrics = $repository->get($symbolInfo->symbolPath);
            $scoreValue = $candidate['score'];

            $perDimensionScores = $this->getPerDimensionScores($metrics);

            $symbolCanonical = $symbolInfo->symbolPath->toCanonical();
            $violationCount = $violationCounts[$symbolCanonical] ?? 0;
            $classCount = $symbolType === SymbolType::Namespace_
                ? (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0)
                : 0;

            $notableMetrics = $this->getNotableMetrics($metrics, $symbolType);

            $offender = $this->offenderBuilder->build(
                [
                    'symbol' => $symbolInfo,
                    'overall' => $scoreValue,
                    'dimensionScores' => $perDimensionScores,
                    'loc' => $metrics->get(
                        $symbolType === SymbolType::Namespace_
                            ? MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)
                            : MetricName::SIZE_CLASS_LOC,
                    ),
                    'notableMetrics' => $notableMetrics,
                ],
                new WorstOffenderEvidence(
                    $violationCount,
                    $classCount,
                    [],
                    [],
                ),
                $warnThreshold,
                $errorThreshold,
            );
            if ($offender !== null) {
                $offenders[] = $offender;
            }
        }

        return $offenders;
    }

    /**
     * @return array<string, float>
     */
    private function getPerDimensionScores(MetricBag $metrics): array
    {
        $scores = [];

        foreach (HealthDimension::all() as $dim) {
            $value = $metrics->get($dim->value);

            if ($value !== null) {
                $scores[$dim->shortName()] = (float) $value;
            }
        }

        return $scores;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    private function countFindingsPerSymbol(array $findings, SymbolType $symbolType, NamespaceTree $tree): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            if ($symbolType === SymbolType::Class_) {
                // Count findings by class
                $classPath = SymbolPath::forClass(
                    $finding->symbolPath->namespace ?? '',
                    $finding->symbolPath->type ?? '',
                );

                if ($finding->symbolPath->type !== null) {
                    $key = $classPath->toCanonical();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            } elseif ($symbolType === SymbolType::Namespace_) {
                // Count findings by namespace, walking up the hierarchy via NamespaceTree
                $ns = $finding->symbolPath->namespace;

                if ($ns !== null && $ns !== '') {
                    $nsPath = SymbolPath::forNamespace($ns);
                    $key = $nsPath->toCanonical();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;

                    foreach ($tree->getAncestors($ns) as $ancestor) {
                        $ancestorPath = SymbolPath::forNamespace($ancestor);
                        $key = $ancestorPath->toCanonical();
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int|float>
     */
    private function getNotableMetrics(MetricBag $metrics, SymbolType $symbolType): array
    {
        $notable = [];
        $keys = $symbolType === SymbolType::Class_
            ? [
                MetricName::STRUCTURE_METHOD_COUNT,
                MetricName::STRUCTURE_PROPERTY_COUNT,
                MetricName::COUPLING_CBO,
                MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Average),
                MetricName::COHESION_TCC,
                MetricName::STRUCTURE_WMC,
                MetricName::agg(MetricName::MAINTAINABILITY_MI, AggregationStrategy::Average),
                MetricName::SIZE_LOC,
            ]
            : [
                MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum),
                MetricName::agg(MetricName::COUPLING_CBO, AggregationStrategy::Average),
                MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Average),
                MetricName::COUPLING_DISTANCE,
                MetricName::agg(MetricName::MAINTAINABILITY_MI, AggregationStrategy::Average),
            ];

        foreach ($keys as $key) {
            $value = $metrics->get($key);

            if ($value !== null) {
                $notable[$key] = $value;
            }
        }

        return $notable;
    }

    /**
     * Checks if a computed metric definition was excluded via --exclude-health.
     *
     * Returns true only when definitions are loaded AND the named metric is not among them.
     * When no definitions are loaded (e.g., in tests), returns false (not excluded).
     */
    private function isDefinitionExcluded(string $name): bool
    {
        $definitions = $this->definitionCatalog->all();

        return $definitions !== [] && !\in_array(
            $name,
            array_map(static fn($definition): string => $definition->name, $definitions),
            true,
        );
    }

    /** @return array{float, float} */
    private function thresholds(HealthDimension $dimension): array
    {
        $definition = $this->definitionCatalog->find($dimension->value);

        return [
            $definition->warningThreshold ?? ($dimension === HealthDimension::Typing ? 80.0 : 50.0),
            $definition->errorThreshold ?? match ($dimension) {
                HealthDimension::Typing => 50.0,
                HealthDimension::Overall => 30.0,
                default => 25.0,
            },
        ];
    }
}
