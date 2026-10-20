<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Observation;

use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;

/** Shared set and counter updates for the two evidence walks. */
final class LayerEvidenceTally
{
    /**
     * @param array<string, array<string, true>> $sets
     * @param list<string> $layers
     *
     * @return array<string, array<string, true>>
     */
    public static function layers(array $sets, array $layers, string $symbol): array
    {
        foreach ($layers as $layer) {
            $sets[$layer][$symbol] = true;
        }

        return $sets;
    }

    /**
     * @param array<string, array<string, true>> $sets
     * @param list<LayerMatch> $matches
     *
     * @return array<string, array<string, true>>
     */
    public static function matches(array $sets, array $matches, string $symbol): array
    {
        foreach ($matches as $match) {
            $sets[$match->layerName][$symbol] = true;
        }

        return $sets;
    }

    /**
     * @param array<string, string> $map
     * @param list<string> $undecidedLayers
     *
     * @return array<string, string>
     */
    public static function unanswered(
        array $map,
        array $undecidedLayers,
        string $canonical,
        string $display,
    ): array {
        if ($undecidedLayers !== []) {
            $map[$canonical] = $display;
        }

        return $map;
    }

    /**
     * @param array<string, array<string, true>> $map
     * @param list<LayerMatch> $established
     *
     * @return array<string, array<string, true>>
     */
    public static function ownerIfExcluded(
        array $map,
        LayerMatch $assigned,
        array $established,
        string $symbol,
    ): array {
        $owner = $established[0] ?? null;
        if ($owner !== null && $owner->layerName !== $assigned->layerName) {
            $map[$owner->layerName][$symbol] = true;
        }

        return $map;
    }

    /**
     * @param list<LayerMatch> $matches
     * @param array<string, int> $assignedHits
     * @param array<string, string> $classes
     */
    public static function edgeEnd(
        array $matches,
        string $canonical,
        string $display,
        array &$assignedHits,
        array &$classes,
        int &$unmatchedEdges,
    ): ?LayerMatch {
        $match = $matches[0] ?? null;
        if ($match === null) {
            $unmatchedEdges++;
            $classes[$canonical] = $display;

            return null;
        }
        $assignedHits[$match->layerName] = ($assignedHits[$match->layerName] ?? 0) + 1;

        return $match;
    }

    /**
     * @param array<string, int> $into
     * @param array<string, int> $from
     *
     * @return array<string, int>
     */
    public static function mergeHits(array $into, array $from): array
    {
        foreach ($from as $layer => $count) {
            $into[$layer] = ($into[$layer] ?? 0) + $count;
        }

        return $into;
    }

    /**
     * @param array<string, array<string, true>> $into
     * @param array<string, array<string, true>> $from
     *
     * @return array<string, array<string, true>>
     */
    public static function mergeSymbols(array $into, array $from): array
    {
        foreach ($from as $layer => $symbols) {
            $into[$layer] = ($into[$layer] ?? []) + $symbols;
        }

        return $into;
    }
}
