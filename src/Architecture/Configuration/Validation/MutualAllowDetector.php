<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Configuration\Validation;

use Qualimetrix\Architecture\Domain\Allow\AllowListEntry;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;

/**
 * Scans the parsed allow-list for symmetric {@code A → B}/{@code B → A} pairs
 * between exact selectors and emits one deferred warning listing every such
 * pair.
 *
 * Mutual allow is legal but usually indicates that the two layers should be
 * merged.
 *
 * **Scope:** only entries whose source AND target are both
 * {@code exact} selectors contribute. Glob and captured selectors deliberately
 * fall outside the check — symmetric overlap between glob ranges is a Step E
 * concern (see ADR 0007). Phase-1 configs use only exact selectors, so the
 * pre-Step-C behaviour is preserved byte-for-byte.
 */
final class MutualAllowDetector
{
    /**
     * @param list<AllowListEntry> $entries
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     */
    public function detect(array $entries, array &$warnings): void
    {
        $exactEdges = self::projectExactEdges($entries);
        $pairs = self::collectMutualPairs($exactEdges);

        if ($pairs === []) {
            return;
        }

        $rendered = implode(', ', array_map(
            static fn(array $pair): string => "{$pair[0]} ↔ {$pair[1]}",
            $pairs,
        ));

        $warnings[] = DeferredWarning::warning(\sprintf(
            'architecture.allow: mutual-allow detected between layer pair(s): %s. Consider merging the layers if this is unintentional.',
            $rendered,
        ));
    }

    /**
     * Projects allow-list entries to a {@code source → targets} map containing
     * only exact↔exact edges. Glob / captured selectors on either side are
     * silently skipped (see class-level docblock).
     *
     * @param list<AllowListEntry> $entries
     *
     * @return array<string, list<string>>
     */
    private static function projectExactEdges(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            if (!$entry->source->isExact()) {
                continue;
            }
            $sourceName = $entry->source->originalString();
            $targetNames = [];
            foreach ($entry->targets as $target) {
                if (!$target->target->isExact()) {
                    continue;
                }
                $targetNames[] = $target->target->originalString();
            }
            $map[$sourceName] = $targetNames;
        }

        return $map;
    }

    /**
     * @param array<string, list<string>> $allowedTargets
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function collectMutualPairs(array $allowedTargets): array
    {
        $pairs = [];
        $seen = [];

        foreach ($allowedTargets as $from => $targets) {
            foreach ($targets as $to) {
                if (!self::isMutualEdge($from, $to, $allowedTargets)) {
                    continue;
                }

                // Order-independent dedup: emit each pair only once.
                $key = $from < $to ? "{$from}|{$to}" : "{$to}|{$from}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $pairs[] = [$from, $to];
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, list<string>> $allowedTargets
     */
    private static function isMutualEdge(string $from, string $to, array $allowedTargets): bool
    {
        if ($from === $to) {
            return false;
        }

        if (!isset($allowedTargets[$to])) {
            return false;
        }

        return \in_array($from, $allowedTargets[$to], true);
    }
}
