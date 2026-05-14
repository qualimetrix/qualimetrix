<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use Qualimetrix\Configuration\Pipeline\DeferredWarning;

/**
 * Scans the normalized allow-targets map for symmetric {@code A → B}/{@code B → A}
 * pairs and appends one deferred warning listing every such pair.
 *
 * Mutual allow is legal but usually indicates that the two layers should be
 * merged.
 */
final class MutualAllowDetector
{
    /**
     * @param array<string, list<string>> $allowedTargets
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     */
    public function detect(array $allowedTargets, array &$warnings): void
    {
        $pairs = self::collectMutualPairs($allowedTargets);

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
