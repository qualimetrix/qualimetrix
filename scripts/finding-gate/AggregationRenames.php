<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Exact aggregation strategy correspondence over both metric vocabularies.
 *
 * @phpstan-import-type MapPair from RenameMaps
 */
final class AggregationRenames
{
    /** @param list<MapPair> $pairs
     * @return array<string, string> */
    public static function of(array $pairs): array
    {
        $strategies = [];
        foreach ($pairs as $pair) {
            if (!\in_array(RenameMaps::METRIC_KEYS, $pair['sources'], true) || !str_starts_with($pair['old'], 'strategy:')) {
                continue;
            }
            if (!str_starts_with($pair['new'], 'strategy:')) {
                throw new GateError('A strategy rename must name a strategy on both sides.');
            }
            $old = substr($pair['old'], 9);
            $new = substr($pair['new'], 9);
            if (isset($strategies[$old]) && $strategies[$old] !== $new) {
                throw new GateError('An aggregation strategy cannot have several declared targets.');
            }
            if (\in_array($new, $strategies, true)) {
                throw new GateError('Aggregation strategy renames must be injective.');
            }
            $strategies[$old] = $new;
        }
        return $strategies;
    }

    /** @param list<MapPair> $pairs
     * @return array{string, array<int, int>} */
    public static function translate(string $text, string $surface, MetricVocabulary $vocabulary, array $pairs): array
    {
        if (!\in_array($surface, ['format:json', 'format:metrics', 'format:html'], true)) {
            return [$text, []];
        }
        $keys = $vocabulary->baseKeys;
        foreach ($pairs as $pair) {
            if (\in_array(RenameMaps::METRIC_KEYS, $pair['sources'], true) && !str_starts_with($pair['old'], 'strategy:')) {
                $keys[] = $pair['old'];
            }
        }
        $lookup = [];
        foreach ($pairs as $index => $pair) {
            if (!str_starts_with($pair['old'], 'strategy:')) {
                continue;
            }
            foreach (array_unique($keys) as $key) {
                $from = '"' . $key . '.' . substr($pair['old'], 9) . '"';
                $lookup[$from] = ['"' . $key . '.' . substr($pair['new'], 9) . '"', $index];
            }
        }
        if ($lookup === []) {
            return [$text, []];
        }
        $hits = [];
        $translated = preg_replace_callback('~' . implode('|', array_map(static fn(string $key): string => preg_quote($key, '~'), array_keys($lookup))) . '~', function (array $match) use ($lookup, &$hits): string {
            [$new, $index] = $lookup[$match[0]];
            $hits[$index] = ($hits[$index] ?? 0) + 1;
            return $new;
        }, $text) ?? throw new GateError('Strategy translation failed.');
        return [$translated, $hits];
    }
}
