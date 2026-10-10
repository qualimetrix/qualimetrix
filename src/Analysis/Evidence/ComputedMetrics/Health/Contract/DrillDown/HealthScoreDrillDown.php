<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown;

use Closure;
use Generator;
use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\ContributorRanker;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Shared logic for namespace-level drill-down: health scores and worst classes.
 *
 * Used by SummaryFormatter and JsonFormatter when --namespace filter is active.
 */
final readonly class HealthScoreDrillDown
{
    private ContributorRanker $contributorRanker;

    public function __construct(
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private HealthDecompositionCatalog $decomposition,
    ) {
        $this->contributorRanker = new ContributorRanker();
    }

    /**
     * Builds scoped health scores by weighted-averaging health from every
     * namespace matched by the explicit selector.
     *
     * @return array<string, HealthScore> Empty array if no matching namespaces found.
     */
    public function buildSubtreeHealthScores(MetricRepositoryInterface $metrics, NamespacePattern $namespace): array
    {
        $allDimensions = HealthDimension::all();
        [$weightedSums, $dimensionWeights] = $this->collectSubtreeWeights($metrics, $namespace, $allDimensions);

        if ($weightedSums === []) {
            return [];
        }

        // Build HealthScore objects from weighted averages
        $healthScores = [];

        foreach ($allDimensions as $dim) {
            $dimension = $dim->value;
            if (!isset($weightedSums[$dimension], $dimensionWeights[$dimension])) {
                continue;
            }

            $avg = $weightedSums[$dimension] / $dimensionWeights[$dimension];
            [$warnThreshold, $errThreshold] = $this->thresholds($dim);
            $dimensionName = $dim->shortName();

            $inputs = $this->classInputs($dimension);
            $classes = $inputs === [] ? [] : iterator_to_array($this->filterClassesByNamespace($metrics, $namespace), false);
            $inputs = $this->decomposition->selectContributorInputs($inputs, array_map(
                static fn(SymbolInfo $symbol): Closure => $metrics->getSubject($symbol->subject ?? throw new LogicException('Class contributor requires exact subject'))->get(...),
                $classes,
            ));
            $contributors = $inputs === []
                ? []
                : $this->contributorRanker->rank(
                    $this->contributorCandidates($metrics, $classes, $inputs),
                    $inputs[0]['direction'],
                );

            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $avg,
                label: $this->scoreLabel($avg, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                // A subtree score is a class-weighted mean of whole namespace
                // scores, so the `.count` behind any one input is not the
                // denominator of this number. The covered share is published
                // where the aggregates are, at project level.
                coverage: HealthCoverage::notApplicable('a subtree score is a class-weighted mean of namespace scores; coverage is published at project level'),
                worstContributors: $contributors,
            );
        }

        return $healthScores;
    }

    /**
     * @param list<HealthDimension> $dimensions
     *
     * @return array{array<string, float>, array<string, int>}
     */
    private function collectSubtreeWeights(MetricRepositoryInterface $metrics, NamespacePattern $namespace, array $dimensions): array
    {
        $weightedSums = [];
        $dimensionWeights = [];

        foreach ($metrics->all(SymbolLevel::Namespace_) as $namespaceInfo) {
            $name = $namespaceInfo->symbolPath->namespace ?? $namespaceInfo->symbolPath->toCanonical();
            if (!$namespace->matches($name)) {
                continue;
            }

            $namespaceMetrics = $metrics->get($namespaceInfo->symbolPath);
            $classCount = max(1, (int) ($namespaceMetrics->get($this->decomposition->classCountMetric()) ?? 1));
            $this->accumulateDimensionWeights($namespaceMetrics, $dimensions, $classCount, $weightedSums, $dimensionWeights);
        }

        return [$weightedSums, $dimensionWeights];
    }

    /**
     * @param list<HealthDimension> $dimensions
     * @param array<string, float> $weightedSums
     * @param array<string, int> $dimensionWeights
     */
    private function accumulateDimensionWeights(
        MetricBag $metrics,
        array $dimensions,
        int $classCount,
        array &$weightedSums,
        array &$dimensionWeights,
    ): void {
        foreach ($dimensions as $dimension) {
            $value = $metrics->get($dimension->value);
            if ($value === null) {
                continue;
            }

            $key = $dimension->value;
            $weightedSums[$key] = ($weightedSums[$key] ?? 0.0) + (float) $value * $classCount;
            $dimensionWeights[$key] = ($dimensionWeights[$key] ?? 0) + $classCount;
        }
    }

    /**
     * Builds health scores for a single class from its metrics.
     *
     * @return array<string, HealthScore> Empty array if class not found.
     */
    public function buildClassHealthScores(MetricRepositoryInterface $metrics, string $classFqn): array
    {
        $subject = self::classSubjectFor($metrics, $classFqn);
        if ($subject === null) {
            return [];
        }

        $classMetrics = $metrics->getSubject($subject);
        $healthScores = [];

        foreach (HealthDimension::all() as $dim) {
            $score = $classMetrics->get($dim->value);

            if ($score === null) {
                continue;
            }

            $scoreValue = (float) $score;
            [$warnThreshold, $errThreshold] = $this->thresholds($dim);
            $dimensionName = $dim->shortName();

            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $scoreValue,
                label: $this->scoreLabel($scoreValue, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                coverage: HealthCoverage::notApplicable("a class score is computed from the class's own metrics, not from an aggregate over symbols"),
            );
        }

        return $healthScores;
    }

    private static function classSubjectFor(MetricRepositoryInterface $metrics, string $classFqn): ?MetricSubject
    {
        $subjects = [];
        foreach ($metrics->allClassDeclarations() as $symbolInfo) {
            if ($symbolInfo->symbolPath->toString() === $classFqn) {
                $subjects[] = $symbolInfo->subject;
            }
        }

        if (\count($subjects) > 1 || ($subjects !== [] && $subjects[0] === null)) {
            throw new LogicException('Class health score selection requires one exact declaration');
        }

        return $subjects[0] ?? null;
    }

    /**
     * @return Generator<SymbolInfo>
     */
    private function filterClassesByNamespace(MetricRepositoryInterface $metrics, NamespacePattern $namespace): Generator
    {
        foreach ($metrics->allClassDeclarations() as $symbolInfo) {
            $classNs = $symbolInfo->symbolPath->namespace ?? '';

            if ($namespace->matches($classNs)) {
                yield $symbolInfo;
            }
        }
    }

    /**
     * The class-level keys a parent score's worst contributors are ranked by.
     *
     * Read from the catalog's contributor list rather than from the shipped
     * decomposition: the decomposition now answers per level, and "what a
     * namespace score was computed from" is not "which class dragged it down".
     *
     * @return list<array{classKey: string, direction: string}>
     */
    private function classInputs(string $dimension): array
    {
        $definition = $this->definitionCatalog->find($dimension);
        if ($definition !== null && !$definition->isBuiltinFormulaForLevel(SymbolLevel::Namespace_)) {
            return [];
        }

        return array_map(static fn(array $input): array => [
            'classKey' => $input['classKey'],
            'direction' => $input['direction'],
        ], $this->decomposition->getDecompositionForClasses($dimension));
    }

    /**
     * @param iterable<SymbolInfo> $classSymbols
     * @param list<array{classKey: string, direction: string}> $inputs
     *
     * @return Generator<array{symbol: SymbolInfo, primaryValue: float|null, contributorMetrics: array<string, int|float>}>
     */
    private function contributorCandidates(
        MetricRepositoryInterface $repository,
        iterable $classSymbols,
        array $inputs,
    ): Generator {
        foreach ($classSymbols as $symbol) {
            $metrics = $repository->getSubject($symbol->subject ?? throw new LogicException('Class contributor requires exact subject'));
            $selection = $this->decomposition->selectContributorMetrics($inputs, $metrics->get(...));

            yield [
                'symbol' => $symbol,
                'primaryValue' => $selection['primaryValue'],
                'contributorMetrics' => $selection['contributorMetrics'],
            ];
        }
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

    private function scoreLabel(float $score, float $warningThreshold, float $errorThreshold): string
    {
        $range = 100 - $warningThreshold;
        if ($score > $warningThreshold + $range * 0.6) {
            return 'Excellent';
        }
        if ($score > $warningThreshold + $range * 0.3) {
            return 'Good';
        }
        if ($score > $warningThreshold) {
            return 'Fair';
        }
        if ($score > $errorThreshold) {
            return 'Poor';
        }

        return 'Critical';
    }
}
