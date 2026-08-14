<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthContributor;
use Qualimetrix\Core\Symbol\SymbolInfo;

/**
 * Ranks closed Health contributor projections without querying Measurement.
 */
final readonly class ContributorRanker
{
    /**
     * Maximum contributors stored per dimension. Formatters may show fewer
     * (e.g., --format-opt=contributors=N slices this list at display time).
     */
    public const int MAX_CONTRIBUTORS = 10;

    /**
     * @param iterable<array{symbol: SymbolInfo, primaryValue: float|null, contributorMetrics: array<string, int|float>}> $candidates
     * @param string $direction Exact Health direction: `higher` or `lower`.
     *
     * @return list<HealthContributor>
     */
    public function rank(
        iterable $candidates,
        string $direction,
        int $limit = self::MAX_CONTRIBUTORS,
    ): array {
        if ($direction !== 'higher' && $direction !== 'lower') {
            throw new InvalidArgumentException(\sprintf('Unsupported contributor ranking direction: %s.', $direction));
        }

        if ($limit <= 0) {
            return [];
        }

        $ranked = [];
        foreach ($candidates as $candidate) {
            if ($candidate['primaryValue'] === null) {
                continue;
            }

            $ranked[] = $candidate;
        }

        usort($ranked, static function (array $a, array $b) use ($direction): int {
            $cmp = $direction === 'higher'
                ? $a['primaryValue'] <=> $b['primaryValue']
                : $b['primaryValue'] <=> $a['primaryValue'];

            return $cmp !== 0
                ? $cmp
                : $a['symbol']->symbolPath->toCanonical() <=> $b['symbol']->symbolPath->toCanonical();
        });

        return array_map(
            static fn(array $candidate): HealthContributor => new HealthContributor(
                className: $candidate['symbol']->symbolPath->type ?? $candidate['symbol']->symbolPath->toCanonical(),
                symbolPath: $candidate['symbol']->symbolPath->toCanonical(),
                metricValues: $candidate['contributorMetrics'],
            ),
            \array_slice($ranked, 0, $limit),
        );
    }
}
