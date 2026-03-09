<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline;

use AiMessDetector\Core\Violation\Violation;
use DateTimeImmutable;

/**
 * Generates baseline from violations.
 */
final readonly class BaselineGenerator
{
    public function __construct(
        private ViolationHasher $hasher,
    ) {}

    /**
     * Generates baseline from list of violations.
     *
     * @param list<Violation> $violations
     */
    public function generate(array $violations): Baseline
    {
        $entries = [];
        $seen = [];

        foreach ($violations as $violation) {
            $canonical = $violation->symbolPath->toCanonical();
            $hash = $this->hasher->hash($violation);
            $key = $canonical . ':' . $hash;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $entries[$canonical] ??= [];
            $entries[$canonical][] = new BaselineEntry(
                rule: $violation->ruleName,
                hash: $hash,
            );
        }

        // Sort keys and entries for deterministic output
        ksort($entries);
        foreach ($entries as $key => $keyEntries) {
            usort($entries[$key], fn(BaselineEntry $a, BaselineEntry $b) => $a->rule <=> $b->rule ?: $a->hash <=> $b->hash);
        }

        return new Baseline(
            version: 4,
            generated: new DateTimeImmutable(),
            entries: $entries,
        );
    }
}
