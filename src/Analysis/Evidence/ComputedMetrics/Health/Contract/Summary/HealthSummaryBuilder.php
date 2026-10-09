<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthContributor;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\ContributorRanker;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\CoverageReader;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds health scores and offender projections from measured evidence.
 *
 * @qmx-threshold complexity.wmc warning=63 error=63 -- Exact subject and ranked-score refusals leave WMC 62 across twelve methods after simplifying the shared routes; moving those guards into the evidence readers transfers the branches. One-point headroom preserves the next growth signal.
 * @qmx-threshold coupling.cbo 21 -- This health projection composes nineteen evidence, catalog and value types (CBO 20); a catalog forwarding method only relocates the same dependency and adds a surface. Keep the direct composition with one-edge headroom.
 */
final readonly class HealthSummaryBuilder
{
    private const int DEFAULT_TOP_NAMESPACES = 10;
    private const int DEFAULT_TOP_CLASSES = 10;

    private ContributorRanker $contributorRanker;
    private CoverageReader $coverage;
    private HealthDecompositionCatalog $decomposition;
    private WorstOffenderBuilder $offenderBuilder;

    public function __construct(
        private HealthMetricCatalog $hintProvider,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {
        $this->contributorRanker = new ContributorRanker();
        $this->decomposition = new HealthDecompositionCatalog();
        $this->coverage = new CoverageReader($this->decomposition);
        $this->offenderBuilder = new WorstOffenderBuilder();
    }

    /** @param list<Finding> $findings */
    public function build(
        MetricRepositoryInterface $metrics,
        NamespaceTree $tree,
        array $findings,
    ): HealthSummary {
        $healthScores = $this->buildHealthScores($metrics, $tree);
        // The levels ranked here are published as
        // RankedOffenderLevels::LEVELS: a caller asking what a `--namespace`
        // value can select has to know which symbols get a canonical name into
        // a comparison, and a second enumeration of that would drift.
        $worstNamespaces = $this->buildWorstOffenders($metrics, $findings, SymbolLevel::Namespace_, self::DEFAULT_TOP_NAMESPACES, $tree);
        $worstClasses = $this->buildWorstOffenders($metrics, $findings, SymbolLevel::Class_, self::DEFAULT_TOP_CLASSES, $tree);

        return new HealthSummary(
            healthScores: $healthScores,
            worstNamespaces: $worstNamespaces,
            worstClasses: $worstClasses,
        );
    }

    /**
     * @return array<string, HealthScore>
     */
    private function buildHealthScores(MetricRepositoryInterface $metrics, NamespaceTree $tree): array
    {
        $projectMetrics = $metrics->get(SymbolPath::forProject());
        $healthScores = [];

        foreach (HealthDimension::all() as $dim) {
            $score = $projectMetrics->get($dim->value);

            $definition = $this->definitionCatalog->find($dim->value);
            if ($score === null && ($definition === null || !$definition->hasLevel(SymbolLevel::Project) || !$definition->isBuiltinFormulaForLevel(SymbolLevel::Project))) {
                continue;
            }

            $scoreValue = $score === null ? null : (float) $score;
            [$warnThreshold, $errThreshold] = $this->thresholds($dim);
            $inputs = $this->decomposition->inputsForFormula($dim->value, SymbolLevel::Project, $projectMetrics->get(...), $definition);
            $decomposition = $this->buildDecomposition($dim->value, $projectMetrics);
            $contributors = $scoreValue === null || ($definition !== null && !$definition->isBuiltinFormulaForLevel(SymbolLevel::Project))
                ? [] : $this->rankContributors($dim->value, $metrics);
            $dimensionName = $dim->shortName();
            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $scoreValue,
                label: $scoreValue === null ? 'Not measured' : $this->hintProvider->getScoreLabel($scoreValue, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                coverage: $inputs === [] && $definition !== null && !$definition->isBuiltinFormulaForLevel(SymbolLevel::Project)
                    ? \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage::notApplicable('the authored formula has no identifiable symbol population')
                    : $this->coverage->read($dim->value, $projectMetrics->get(...), $inputs),
                decomposition: $decomposition,
                worstContributors: $contributors,
            );
        }

        // Show the absent typing dimension when other dimensions exist,
        // unless typing was explicitly excluded via --exclude-health
        $typingDefinition = $this->definitionCatalog->find(HealthDimension::Typing->value);
        if ($healthScores !== [] && !isset($healthScores['typing']) && !$this->isDefinitionExcluded(HealthDimension::Typing->value)
            && ($typingDefinition === null || $typingDefinition->isBuiltinFormulaForLevel(SymbolLevel::Project))) {
            [$typingWarning, $typingError] = $this->thresholds(HealthDimension::Typing);
            $healthScores['typing'] = new HealthScore(
                name: 'typing',
                score: null,
                label: 'Not measured',
                warningThreshold: $typingWarning,
                errorThreshold: $typingError,
                coverage: $this->coverage->read(HealthDimension::Typing->value, $projectMetrics->get(...)),
            );
        }

        return $healthScores;
    }

    /**
     * The classes that pushed a project score down, worst first.
     *
     * @return list<HealthContributor>
     */
    private function rankContributors(string $dimension, MetricRepositoryInterface $metrics): array
    {
        $inputs = $this->hintProvider->getDecompositionForClasses($dimension);

        if ($inputs === []) {
            return [];
        }

        return $this->contributorRanker->rank(
            array_map(function ($symbol) use ($metrics, $inputs): array {
                $subject = $symbol->subject ?? throw new LogicException('Class contributor requires an exact subject');
                $selection = $this->decomposition->selectContributorMetrics(
                    $inputs,
                    $metrics->getSubject($subject)->get(...),
                );

                return [
                    'symbol' => $symbol,
                    'primaryValue' => $selection['primaryValue'],
                    'contributorMetrics' => $selection['contributorMetrics'],
                    'primaryDirection' => ($inputs[0]['classKey'] ?? null) === MetricName::COHESION_TCC
                        && $metrics->getSubject($subject)->get(MetricName::COHESION_TCC) === null
                        ? 'lower' : $inputs[0]['direction'],
                ];
            }, iterator_to_array($metrics->allClassDeclarations(), false)),
            $inputs[0]['direction'],
        );
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
            $definition = $this->definitionCatalog->find($dimension);
            if ($definition !== null && !$definition->isBuiltinFormulaForLevel(SymbolLevel::Project)) {
                return [];
            }

            return $this->buildTypingDecomposition($projectMetrics);
        }

        $definition = $this->definitionCatalog->find($dimension);
        $inputs = $definition !== null && !$definition->isBuiltinFormulaForLevel(SymbolLevel::Project)
            ? $this->decomposition->inputsForFormula($dimension, SymbolLevel::Project, $projectMetrics->get(...), $definition)
            : $this->decomposition->inputsFor($dimension, SymbolLevel::Project);
        $metricKeys = array_column($inputs, 'key');
        $items = [];

        foreach ($metricKeys as $metricKey) {
            $value = $projectMetrics->get($metricKey);

            $floatValue = $value === null ? null : (float) $value;
            $label = $this->hintProvider->getLabel($metricKey) ?? $metricKey;
            $goodValue = $this->hintProvider->getGoodValue($metricKey) ?? '';
            $direction = $this->hintProvider->getDirection($metricKey) ?? 'lower_is_better';
            $explanation = $floatValue === null ? '' : $this->hintProvider->getExplanation($metricKey, $floatValue);

            $items[] = new DecompositionItem(
                metricKey: $metricKey,
                humanName: $label,
                value: $floatValue,
                goodValue: $goodValue,
                direction: $direction,
                explanation: $explanation,
                coverage: $this->coverage->forInput($dimension, $metricKey, $projectMetrics->get(...)),
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
            ['label' => 'Parameter types', 'typed' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, AggregationStrategy::Sum)],
            ['label' => 'Return types', 'typed' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, AggregationStrategy::Sum)],
            ['label' => 'Property types', 'typed' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, AggregationStrategy::Sum)],
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
        SymbolLevel $level,
        int $limit,
        NamespaceTree $tree,
    ): array {
        [$warnThreshold, $errorThreshold] = $this->thresholds(HealthDimension::Overall);
        $candidates = self::rankedCandidates($repository, $level);
        $violationCounts = $this->countFindingsPerSymbol($repository, $findings, $level, $tree);
        $offenders = [];

        foreach (\array_slice($candidates, 0, $limit) as $candidate) {
            $offenders[] = $this->offenderFromCandidate($candidate, $violationCounts, $level, $warnThreshold, $errorThreshold)
                ?? throw new LogicException('Ranked offender candidates require an overall score');
        }

        return $offenders;
    }

    /** @return list<array{score: float, info: \Qualimetrix\Core\Symbol\SymbolInfo, metrics: MetricBag}> */
    private static function rankedCandidates(MetricRepositoryInterface $repository, SymbolLevel $level): array
    {
        $candidates = [];

        $symbols = $level === SymbolLevel::Class_ ? $repository->allClassDeclarations() : $repository->all($level);
        foreach ($symbols as $symbolInfo) {
            $metrics = $symbolInfo->subject === null
                ? $repository->get($symbolInfo->symbolPath)
                : $repository->getSubject($symbolInfo->subject);
            $healthOverall = $metrics->get(HealthDimension::Overall->value);

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;

            // Skip namespaces with no direct classes (e.g., root namespace
            // containers like "PHPUnit"). The unsuffixed key is the namespace's
            // own count; the `.sum` this used to read is the subtree's, which is
            // positive for exactly the containers named here — so the guard let
            // through the case it was written for, and a container ranked beside
            // its own children carrying their weight.
            if ($level === SymbolLevel::Namespace_ && (int) ($metrics->get(MetricName::SIZE_CLASS_COUNT) ?? 0) === 0) {
                continue;
            }

            $candidates[] = ['score' => $scoreValue, 'info' => $symbolInfo, 'metrics' => $metrics];
        }

        // Sort by score ascending (worst first), with stable secondary sort by canonical path
        usort($candidates, static fn(array $a, array $b): int => ($a['score'] <=> $b['score']) !== 0 ? ($a['score'] <=> $b['score'])
                : (($a['info']->subject?->toCanonical() ?? $a['info']->symbolPath->toCanonical())
                    <=> ($b['info']->subject?->toCanonical() ?? $b['info']->symbolPath->toCanonical())));

        return $candidates;
    }

    /**
     * @param array{score: float, info: \Qualimetrix\Core\Symbol\SymbolInfo, metrics: MetricBag} $candidate
     * @param array<string, int> $violationCounts
     */
    private function offenderFromCandidate(
        array $candidate,
        array $violationCounts,
        SymbolLevel $level,
        float $warnThreshold,
        float $errorThreshold,
    ): ?WorstOffender {
        $symbolInfo = $candidate['info'];
        $metrics = $candidate['metrics'];
        $symbolCanonical = $symbolInfo->subject?->toCanonical() ?? $symbolInfo->symbolPath->toCanonical();
        $classCount = $level === SymbolLevel::Namespace_
            ? (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0)
            : 0;

        return $this->offenderBuilder->build(
            [
                'symbol' => $symbolInfo,
                'overall' => $candidate['score'],
                'dimensionScores' => $this->getPerDimensionScores($metrics),
                'loc' => $metrics->get(
                    $level === SymbolLevel::Namespace_
                        ? MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)
                        : MetricName::SIZE_CLASS_LOC,
                ),
                'notableMetrics' => $this->getNotableMetrics($metrics, $level),
            ],
            new WorstOffenderEvidence($violationCounts[$symbolCanonical] ?? 0, $classCount, [], []),
            $warnThreshold,
            $errorThreshold,
        );
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
    private function countFindingsPerSymbol(MetricRepositoryInterface $repository, array $findings, SymbolLevel $level, NamespaceTree $tree): array
    {
        if ($level === SymbolLevel::Class_) {
            return $this->offenderBuilder->countClassFindings($repository, $findings);
        }
        $counts = [];

        foreach ($findings as $finding) {
            $ns = $finding->symbolPath->namespace;
            if ($ns === null || $ns === '') {
                continue;
            }
            $key = SymbolPath::forNamespace($ns)->toCanonical();
            $counts[$key] = ($counts[$key] ?? 0) + 1;

            foreach ($tree->getAncestors($ns) as $ancestor) {
                $key = SymbolPath::forNamespace($ancestor)->toCanonical();
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int|float>
     */
    private function getNotableMetrics(MetricBag $metrics, SymbolLevel $level): array
    {
        $notable = [];
        $keys = $level === SymbolLevel::Class_
            ? [
                MetricName::SIZE_METHOD_COUNT,
                MetricName::SIZE_PROPERTY_COUNT,
                MetricName::COUPLING_CBO,
                MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Average),
                MetricName::COHESION_TCC,
                MetricName::COMPLEXITY_WMC,
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
