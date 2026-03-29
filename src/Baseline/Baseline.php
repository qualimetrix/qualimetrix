<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use DateTimeImmutable;

/**
 * Value object representing a baseline file.
 *
 * Baseline contains a snapshot of known violations that should be ignored.
 * This allows introducing the tool into legacy projects without fixing all existing issues first.
 *
 * Version 5 (current): Keys are canonical symbol paths. Each entry contains a 16-char hash
 *   (xxh3/sha256) computed from rule|namespace|type|member|violationCode.
 *   Versions 2/3/4 are no longer supported (BaselineLoader rejects them).
 */
final readonly class Baseline
{
    /**
     * @param array<string, list<BaselineEntry>> $entries canonical => entries
     */
    public function __construct(
        public int $version,
        public DateTimeImmutable $generated,
        public array $entries,
    ) {}

    /**
     * Checks if baseline contains a violation with given hash for specified canonical path.
     */
    public function contains(string $canonical, string $hash): bool
    {
        if (!isset($this->entries[$canonical])) {
            return false;
        }

        foreach ($this->entries[$canonical] as $entry) {
            if ($entry->hash === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns total number of violations in baseline.
     */
    public function count(): int
    {
        return array_sum(array_map(count(...), $this->entries));
    }

    /**
     * Returns violations that were in baseline but are not in current hashes.
     * Useful for tracking debt payoff progress.
     *
     * @param array<string, list<string>> $currentHashes canonical => hashes
     *
     * @return array<string, list<BaselineEntry>> canonical => resolved entries
     */
    public function diff(array $currentHashes): array
    {
        $resolved = [];

        foreach ($this->entries as $canonical => $entries) {
            $canonicalCurrentHashes = $currentHashes[$canonical] ?? [];

            foreach ($entries as $entry) {
                if (!\in_array($entry->hash, $canonicalCurrentHashes, true)) {
                    $resolved[$canonical] ??= [];
                    $resolved[$canonical][] = $entry;
                }
            }
        }

        return $resolved;
    }

    /**
     * Returns list of canonical keys in baseline that no longer have corresponding symbols.
     *
     * @param list<string> $existingCanonicals
     *
     * @return list<string>
     */
    public function getStaleKeys(array $existingCanonicals): array
    {
        $existingSet = array_flip($existingCanonicals);
        $staleKeys = [];

        foreach (array_keys($this->entries) as $canonical) {
            if (!isset($existingSet[$canonical])) {
                $staleKeys[] = $canonical;
            }
        }

        return $staleKeys;
    }
}
