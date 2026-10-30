<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * The order in which the files of an import graph are merged: the C3
 * linearization of the root, lowest precedence first, the root last.
 *
 * The written list is last-wins while C3 reads the highest priority first, so
 * each list is given reversed as bases and the result is reversed once at the
 * output. The graph must be acyclic; callers refuse cycles beforehand.
 */
final class ImportLinearization
{
    /**
     * @param array<string, list<string>> $imports key => keys it imports as written, lowest first; every reachable key is present
     * @param Closure(string): ConfigurationOrigin $origin the refusal is raised on the origin of the file whose merge is stuck
     * @param Closure(string, string): string $position (importer key, imported key) => the entry as written, e.g. `imports[1]`
     *
     * @throws ConfigurationRefusal
     *
     * @return list<string>
     */
    public static function of(string $root, array $imports, Closure $origin, Closure $position): array
    {
        $memo = [];
        $highestFirst = self::linearize($root, $imports, $origin, $position, $memo, []);

        return array_reverse($highestFirst);
    }

    /**
     * @param array<string, list<string>> $imports
     * @param Closure(string): ConfigurationOrigin $origin
     * @param Closure(string, string): string $position
     * @param array<string, list<string>> $memo highest first
     * @param array<string, true> $inProgress
     *
     * @return list<string> highest first
     */
    private static function linearize(string $key, array $imports, Closure $origin, Closure $position, array &$memo, array $inProgress): array
    {
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        if (isset($inProgress[$key])) {
            throw new LogicException(\sprintf('Import graph has a cycle through "%s"; cycles are refused before linearization.', $key));
        }
        $written = $imports[$key] ?? throw new LogicException(\sprintf('Import graph has no entry for "%s".', $key));
        $inProgress[$key] = true;

        $bases = array_reverse($written);
        $sequences = [];
        foreach ($bases as $base) {
            $sequences[] = ['owner' => $base, 'items' => self::linearize($base, $imports, $origin, $position, $memo, $inProgress)];
        }
        $sequences[] = ['owner' => null, 'items' => $bases];

        $merged = self::merge($sequences, $key, $memo, $origin, $position);

        return $memo[$key] = [$key, ...$merged];
    }

    /**
     * @param list<array{owner: ?string, items: list<string>}> $sequences the bases' linearizations, then the base list itself (no owner)
     * @param array<string, list<string>> $memo
     * @param Closure(string): ConfigurationOrigin $origin
     * @param Closure(string, string): string $position
     *
     * @return list<string>
     */
    private static function merge(array $sequences, string $key, array $memo, Closure $origin, Closure $position): array
    {
        $result = [];
        while (true) {
            $sequences = array_values(array_filter($sequences, static fn(array $sequence): bool => $sequence['items'] !== []));
            if ($sequences === []) {
                return $result;
            }

            $head = self::admissibleHead($sequences);
            if ($head === null) {
                throw self::stuck($key, $sequences, $memo, $origin, $position);
            }
            $result[] = $head;
            foreach ($sequences as $index => $sequence) {
                if ($sequence['items'][0] === $head) {
                    $sequences[$index]['items'] = \array_slice($sequence['items'], 1);
                }
            }
        }
    }

    /** @param non-empty-list<array{owner: ?string, items: non-empty-list<string>}> $sequences */
    private static function admissibleHead(array $sequences): ?string
    {
        foreach ($sequences as $sequence) {
            $candidate = $sequence['items'][0];
            foreach ($sequences as $other) {
                if (\in_array($candidate, \array_slice($other['items'], 1), true)) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Every remaining element has a predecessor in some remaining sequence (a
     * head is blocked by one, any other element has one in its own), so
     * walking predecessors back from any element must close a cycle.
     *
     * @param non-empty-list<array{owner: ?string, items: non-empty-list<string>}> $sequences
     * @param array<string, list<string>> $memo
     * @param Closure(string): ConfigurationOrigin $origin
     * @param Closure(string, string): string $position
     */
    private static function stuck(string $key, array $sequences, array $memo, Closure $origin, Closure $position): ConfigurationRefusal
    {
        $predecessor = [];
        foreach ($sequences as $sequence) {
            $items = $sequence['items'];
            for ($i = 1, $count = \count($items); $i < $count; ++$i) {
                $predecessor[$items[$i]] ??= [$items[$i - 1], $sequence['owner']];
            }
        }

        $at = $sequences[0]['items'][0];
        $visited = [];
        while (!isset($visited[$at])) {
            $visited[$at] = \count($visited);
            $at = $predecessor[$at][0] ?? throw new LogicException('A stuck C3 merge must contain a constraint cycle.');
        }

        $steps = [];
        $current = $at;
        do {
            [$above, $owner] = $predecessor[$current];
            $steps[] = self::describeEdge($above, $current, $owner === null ? null : $memo[$owner], $key, $origin, $position);
            $current = $above;
        } while ($current !== $at);

        $label = static fn(string $file): string => self::label($file, $origin);

        return ConfigurationRefusal::at(
            $origin($key),
            RefusedPosition::open([ConfigurationRoot::Imports->value], ConfigurationRoot::Imports->value),
            \sprintf(
                'The imports of %s cannot be ordered: the files it builds on demand contradictory precedence (%s). Reorder the entries or drop one of them.',
                $label($key),
                implode('; ', array_reverse($steps)),
            ),
        );
    }

    /**
     * @param ?list<string> $derived highest first: the linearization that demands $above over $below, or null for the written list
     * @param Closure(string): ConfigurationOrigin $origin
     * @param Closure(string, string): string $position
     */
    private static function describeEdge(string $above, string $below, ?array $derived, string $key, Closure $origin, Closure $position): string
    {
        $label = static fn(string $file): string => self::label($file, $origin);
        if ($derived === null) {
            return \sprintf(
                '%s above %s, as %s writes %s after %s',
                $label($above),
                $label($below),
                $label($key),
                $position($key, $above),
                $position($key, $below),
            );
        }

        return \sprintf(
            '%s above %s, derived from the merge order of %s (%s)',
            $label($above),
            $label($below),
            $label($derived[0]),
            implode(' ', array_map($label, array_reverse($derived))),
        );
    }

    /** @param Closure(string): ConfigurationOrigin $origin */
    private static function label(string $file, Closure $origin): string
    {
        $named = $origin($file);

        return '"' . ($named->locator() ?? $named->describe()) . '"';
    }
}
