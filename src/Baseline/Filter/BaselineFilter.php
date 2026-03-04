<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline\Filter;

use AiMessDetector\Baseline\Baseline;
use AiMessDetector\Baseline\BaselineEntry;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Core\Violation\Filter\ViolationFilterInterface;
use AiMessDetector\Core\Violation\Violation;

/**
 * Filters out violations that exist in baseline.
 *
 * This allows ignoring known violations from legacy code while detecting new issues.
 */
final readonly class BaselineFilter implements ViolationFilterInterface
{
    public function __construct(
        private Baseline $baseline,
        private ViolationHasher $hasher,
    ) {}

    /**
     * Returns true if violation should be included (not in baseline).
     * Returns false if violation is in baseline (should be filtered out).
     */
    public function shouldInclude(Violation $violation): bool
    {
        $hash = $this->hasher->hash($violation);
        $canonical = $violation->symbolPath->toCanonical();

        return !$this->baseline->contains($canonical, $hash);
    }

    /**
     * Returns violations that were in baseline but no longer appear in current run.
     * Useful for tracking debt payoff progress.
     *
     * @param list<Violation> $violations Current violations from analysis
     *
     * @return array<string, list<BaselineEntry>> canonical => resolved entries
     */
    public function getResolvedFromBaseline(array $violations): array
    {
        $currentHashes = [];

        foreach ($violations as $violation) {
            $canonical = $violation->symbolPath->toCanonical();
            $hash = $this->hasher->hash($violation);

            $currentHashes[$canonical] ??= [];
            $currentHashes[$canonical][] = $hash;
        }

        return $this->baseline->diff($currentHashes);
    }
}
