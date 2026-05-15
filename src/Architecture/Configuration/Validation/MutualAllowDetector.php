<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Configuration\Validation;

use Qualimetrix\Architecture\Domain\Allow\AllowListEntry;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Dependency\DependencyType;

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
 *
 * **Relations & opt-out awareness (Phase 2 Step G + D5).** An edge carrying a
 * {@code relations:} whitelist contributes only a partial allow — the
 * "mutual-allow" signal is meaningful only if the two opposing edges share at
 * least one {@see DependencyType}, otherwise the layers are not actually
 * symmetric (A can extend B, B can call A statically — the pair is not
 * collapsible). Similarly, an edge that explicitly sets
 * {@code allow_cross_instance: true} signals user intent to permit the
 * directional pattern; firing a mutual-allow warning would be redundant noise
 * (D5 caveat — opt-out silences related diagnostics).
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
     * Projects allow-list entries to a {@code source → (target → edge)} map
     * containing only exact↔exact edges. Glob / captured selectors on either
     * side are silently skipped (see class-level docblock).
     *
     * Each edge retains the {@code relations} whitelist ({@code null} = all
     * relations) and the {@code allowCrossInstance} opt-out flag so the
     * mutual-edge check can consult them.
     *
     * @param list<AllowListEntry> $entries
     *
     * @return array<string, array<string, array{relations: list<DependencyType>|null, allowCrossInstance: bool}>>
     */
    private static function projectExactEdges(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            if (!$entry->source->isExact()) {
                continue;
            }
            $sourceName = $entry->source->originalString();
            $edges = [];
            foreach ($entry->targets as $target) {
                if (!$target->target->isExact()) {
                    continue;
                }
                $edges[$target->target->originalString()] = [
                    'relations' => $target->relations,
                    'allowCrossInstance' => $target->allowCrossInstance,
                ];
            }
            $map[$sourceName] = $edges;
        }

        return $map;
    }

    /**
     * @param array<string, array<string, array{relations: list<DependencyType>|null, allowCrossInstance: bool}>> $exactEdges
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function collectMutualPairs(array $exactEdges): array
    {
        $pairs = [];
        $seen = [];

        foreach ($exactEdges as $from => $edges) {
            foreach ($edges as $to => $edgeForward) {
                if (!self::isMutualEdge($from, $to, $exactEdges)) {
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
     * @param array<string, array<string, array{relations: list<DependencyType>|null, allowCrossInstance: bool}>> $exactEdges
     */
    private static function isMutualEdge(string $from, string $to, array $exactEdges): bool
    {
        if ($from === $to) {
            return false;
        }

        if (!isset($exactEdges[$to][$from])) {
            return false;
        }

        $forward = $exactEdges[$from][$to];
        $backward = $exactEdges[$to][$from];

        // D5 opt-out: either direction explicitly accepts the directional
        // pattern → user intent is documented, suppress redundant warning.
        if ($forward['allowCrossInstance'] || $backward['allowCrossInstance']) {
            return false;
        }

        return self::relationsIntersect($forward['relations'], $backward['relations']);
    }

    /**
     * Returns true when the two relation filters share at least one
     * {@see DependencyType}. {@code null} means "all relations" — it
     * intersects with every other filter (null or non-empty list).
     *
     * @param list<DependencyType>|null $a
     * @param list<DependencyType>|null $b
     */
    private static function relationsIntersect(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }

        foreach ($a as $type) {
            if (\in_array($type, $b, true)) {
                return true;
            }
        }

        return false;
    }
}
