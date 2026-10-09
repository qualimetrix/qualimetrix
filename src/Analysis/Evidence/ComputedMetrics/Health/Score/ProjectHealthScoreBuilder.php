<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\CoverageUnit;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthContributor;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

final readonly class ProjectHealthScoreBuilder
{
    private HealthDecompositionCatalog $decomposition;
    private HealthDecompositionBuilder $decompositionBuilder;
    private CoverageReader $coverage;
    private ContributorRanker $contributorRanker;

    public function __construct(
        private HealthMetricCatalog $hintProvider,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {
        $this->decomposition = new HealthDecompositionCatalog();
        $this->coverage = new CoverageReader($this->decomposition);
        $this->decompositionBuilder = new HealthDecompositionBuilder($this->hintProvider, $this->coverage);
        $this->contributorRanker = new ContributorRanker();
    }

    /** @return array<string, HealthScore> */
    public function build(MetricRepositoryInterface $metrics): array
    {
        $projectMetrics = $metrics->get(SymbolPath::forProject());
        // Aggregation publishes no project bag when discovery or collection
        // leaves no symbols. Missing populations are unknown, not zero.
        if ($projectMetrics->all() === []) {
            return [];
        }

        $scores = [];
        foreach (HealthDimension::all() as $dimension) {
            $score = $this->dimensionScore($dimension, $projectMetrics, $metrics);
            if ($score !== null) {
                $scores[$dimension->shortName()] = $score;
            }
        }

        if ($scores === [] || isset($scores['typing'])) {
            return $scores;
        }
        $typing = $this->absentTyping($projectMetrics);
        if ($typing !== null) {
            $scores['typing'] = $typing;
        }

        return $scores;
    }

    private function dimensionScore(HealthDimension $dimension, MetricBag $projectMetrics, MetricRepositoryInterface $metrics): ?HealthScore
    {
        $value = $projectMetrics->get($dimension->value);
        $definition = $this->definitionCatalog->find($dimension->value);
        if ($value === null && !self::includesAbsence($definition)) {
            return null;
        }

        $authored = $definition?->isBuiltinFormulaForLevel(SymbolLevel::Project) === false;
        $inputs = $this->decomposition->inputsForFormula($dimension->value, SymbolLevel::Project, $projectMetrics->get(...), $definition);
        $score = $value === null ? null : (float) $value;
        [$warning, $error] = $this->thresholds($dimension);

        return new HealthScore(
            name: $dimension->shortName(),
            score: $score,
            label: $score === null ? 'Not measured' : $this->hintProvider->getScoreLabel($score, $warning, $error),
            warningThreshold: $warning,
            errorThreshold: $error,
            coverage: $this->dimensionCoverage($dimension->value, $projectMetrics, $inputs, $definition),
            decomposition: $this->dimensionDecomposition($dimension, $projectMetrics, $inputs, $definition),
            worstContributors: $score === null || $authored ? [] : $this->rankContributors($dimension->value, $metrics),
        );
    }

    private static function includesAbsence(?ComputedMetricDefinition $definition): bool
    {
        return $definition !== null
            && $definition->hasLevel(SymbolLevel::Project)
            && $definition->isBuiltinFormulaForLevel(SymbolLevel::Project);
    }

    /** @param list<array{key: string, sources: list<string>, label: string, direction: string, coverage: array{count: string, unit: CoverageUnit}|null}> $inputs */
    private function dimensionCoverage(string $dimension, MetricBag $metrics, array $inputs, ?ComputedMetricDefinition $definition): HealthCoverage
    {
        if ($definition?->isBuiltinFormulaForLevel(SymbolLevel::Project) === false && $inputs === []) {
            return HealthCoverage::notApplicable('the authored formula has no identifiable symbol population');
        }

        return $this->coverage->read($dimension, $metrics->get(...), $inputs);
    }

    /**
     * @param list<array{key: string, sources: list<string>, label: string, direction: string, coverage: array{count: string, unit: CoverageUnit}|null}> $inputs
     *
     * @return list<DecompositionItem>
     */
    private function dimensionDecomposition(HealthDimension $dimension, MetricBag $metrics, array $inputs, ?ComputedMetricDefinition $definition): array
    {
        $authored = $definition?->isBuiltinFormulaForLevel(SymbolLevel::Project) === false;

        if ($dimension === HealthDimension::Typing) {
            return $authored ? [] : $this->decompositionBuilder->buildTyping($metrics);
        }

        if (!$authored) {
            $inputs = $this->decomposition->inputsFor($dimension->value, SymbolLevel::Project);
        }

        return $this->decompositionBuilder->build($dimension->value, $metrics, $inputs);
    }

    private function absentTyping(MetricBag $metrics): ?HealthScore
    {
        $definition = $this->definitionCatalog->find(HealthDimension::Typing->value);
        if ($this->isDefinitionExcluded(HealthDimension::Typing->value)
            || $definition?->isBuiltinFormulaForLevel(SymbolLevel::Project) === false) {
            return null;
        }

        [$warning, $error] = $this->thresholds(HealthDimension::Typing);

        return new HealthScore(
            name: 'typing',
            score: null,
            label: 'Not measured',
            warningThreshold: $warning,
            errorThreshold: $error,
            coverage: $this->coverage->read(HealthDimension::Typing->value, $metrics->get(...)),
        );
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

        $classes = iterator_to_array($metrics->allClassDeclarations(), false);
        $inputs = $this->decomposition->selectContributorInputs($inputs, array_map(
            static fn($symbol): Closure => $metrics->getSubject($symbol->subject ?? throw new LogicException('Class contributor requires an exact subject'))->get(...),
            $classes,
        ));

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
                ];
            }, $classes),
            $inputs[0]['direction'],
        );
    }

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
