<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolInfo;

/**
 * Ranks classes by their worst metric values for a health dimension.
 *
 * Shared by SummaryEnricher (project-wide) and NamespaceDrillDown (namespace-scoped).
 */
final readonly class ContributorRanker
{
    /**
     * Maximum contributors stored per dimension. Formatters may show fewer
     * (e.g., --format-opt=contributors=N slices this list at display time).
     */
    public const int MAX_CONTRIBUTORS = 10;

    public function __construct(
        private MetricHintProvider $hintProvider,
    ) {}

    /**
     * Builds worst contributors for a health dimension by finding classes
     * with the worst primary metric values.
     *
     * @param iterable<SymbolInfo> $classSymbols Classes to rank (pre-filtered by caller)
     *
     * @return list<HealthContributor>
     */
    public function rank(
        string $dimension,
        MetricRepositoryInterface $metrics,
        iterable $classSymbols,
        int $limit = self::MAX_CONTRIBUTORS,
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $inputs = $this->hintProvider->getDecompositionForClasses($dimension);

        if ($inputs === []) {
            return [];
        }

        // Primary metric is first in decomposition list
        $primaryInput = $inputs[0];
        $primaryKey = $primaryInput['classKey'];
        $primaryDirection = $primaryInput['direction'];

        /** @var list<array{className: string, symbolPath: string, primaryValue: float, metrics: array<string, float|int>}> $candidates */
        $candidates = [];

        foreach ($classSymbols as $symbolInfo) {
            $classMetrics = $metrics->get($symbolInfo->symbolPath);
            $primaryValue = $classMetrics->get($primaryKey);

            if ($primaryValue === null) {
                continue;
            }

            $className = $symbolInfo->symbolPath->type ?? $symbolInfo->symbolPath->toCanonical();

            $metricValues = [];

            foreach ($inputs as $input) {
                $value = $classMetrics->get($input['classKey']);

                if ($value !== null) {
                    $metricValues[$input['classKey']] = \is_float($value) ? $value : (int) $value;
                }
            }

            $candidates[] = [
                'className' => $className,
                'symbolPath' => $symbolInfo->symbolPath->toCanonical(),
                'primaryValue' => (float) $primaryValue,
                'metrics' => $metricValues,
            ];
        }

        // Sort: "worst" depends on direction
        // lower_is_better → worst = highest → sort descending
        // higher_is_better → worst = lowest → sort ascending
        usort($candidates, static function (array $a, array $b) use ($primaryDirection): int {
            $cmp = $primaryDirection === 'higher'
                ? $a['primaryValue'] <=> $b['primaryValue'] // ascending = worst first for higher_is_better
                : $b['primaryValue'] <=> $a['primaryValue']; // descending = worst first for lower_is_better

            return $cmp !== 0 ? $cmp : $a['className'] <=> $b['className'];
        });

        $contributors = [];

        foreach (\array_slice($candidates, 0, $limit) as $candidate) {
            $contributors[] = new HealthContributor(
                className: $candidate['className'],
                symbolPath: $candidate['symbolPath'],
                metricValues: $candidate['metrics'],
            );
        }

        return $contributors;
    }
}
