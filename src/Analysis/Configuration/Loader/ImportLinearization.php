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
 * output. An entry is a group of files: the matches of one glob. The groups of
 * a list are ordered against each other, the members of one group are not —
 * only the files they import decide between them, and where nothing does the
 * earlier member of the given order wins. The graph must be acyclic; callers
 * refuse cycles beforehand.
 */
final class ImportLinearization
{
    /**
     * @param array<string, list<list<string>>> $imports key => its entries as written, lowest first, each a group of keys: one for an explicit entry, a glob's matches in byte order; every reachable key is present
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
     * @param array<string, list<list<string>>> $imports
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

        $groups = array_map(static fn(array $group): array => array_reverse($group), array_reverse($written));
        $sequences = [];
        foreach ($groups as $group) {
            foreach ($group as $base) {
                $sequences[] = ['owner' => $base, 'items' => self::linearize($base, $imports, $origin, $position, $memo, $inProgress)];
            }
        }

        $merged = self::merge($sequences, $groups, $key, $memo, $origin, $position);

        return $memo[$key] = [$key, ...$merged];
    }

    /**
     * @param list<array{owner: string, items: list<string>}> $sequences the bases' linearizations
     * @param list<list<string>> $groups the entries of the list, highest first, members in the order tried
     * @param array<string, list<string>> $memo
     * @param Closure(string): ConfigurationOrigin $origin
     * @param Closure(string, string): string $position
     *
     * @return list<string>
     */
    private static function merge(array $sequences, array $groups, string $key, array $memo, Closure $origin, Closure $position): array
    {
        $result = [];
        while (true) {
            $sequences = array_values(array_filter($sequences, static fn(array $sequence): bool => $sequence['items'] !== []));
            $groups = array_values(array_filter($groups, static fn(array $group): bool => $group !== []));
            if ($sequences === [] && $groups === []) {
                return $result;
            }

            $head = self::admissibleHead($sequences, $groups);
            if ($head === null) {
                throw self::stuck($key, $sequences, $groups, $memo, $origin, $position);
            }
            $result[] = $head;
            foreach ($sequences as $index => $sequence) {
                if ($sequence['items'][0] === $head) {
                    $sequences[$index]['items'] = \array_slice($sequence['items'], 1);
                }
            }
            $groups = array_map(static fn(array $group): array => array_values(array_diff($group, [$head])), $groups);
        }
    }

    /**
     * A candidate is a head of a base's linearization or any member of the
     * first group left. It is admissible while it is in no linearization's
     * tail and in no later group: a later entry never goes ahead of an earlier
     * one that still has members.
     *
     * @param list<array{owner: string, items: non-empty-list<string>}> $sequences
     * @param list<non-empty-list<string>> $groups
     */
    private static function admissibleHead(array $sequences, array $groups): ?string
    {
        $candidates = [...array_map(static fn(array $sequence): string => $sequence['items'][0], $sequences), ...($groups[0] ?? [])];
        foreach ($candidates as $candidate) {
            foreach ($sequences as $sequence) {
                if (\in_array($candidate, \array_slice($sequence['items'], 1), true)) {
                    continue 2;
                }
            }
            foreach (\array_slice($groups, 1) as $later) {
                if (\in_array($candidate, $later, true)) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Every remaining element has a predecessor (a file in a linearization's
     * tail has one in its own sequence, a file of a later group has one in the
     * group before; a head blocked by either is such a file), so walking
     * predecessors back from any element must close a cycle.
     *
     * @param list<array{owner: string, items: non-empty-list<string>}> $sequences
     * @param list<non-empty-list<string>> $groups
     * @param array<string, list<string>> $memo
     * @param Closure(string): ConfigurationOrigin $origin
     * @param Closure(string, string): string $position
     */
    private static function stuck(string $key, array $sequences, array $groups, array $memo, Closure $origin, Closure $position): ConfigurationRefusal
    {
        $predecessor = [];
        foreach ($sequences as $sequence) {
            $items = $sequence['items'];
            for ($i = 1, $count = \count($items); $i < $count; ++$i) {
                $predecessor[$items[$i]] ??= [$items[$i - 1], $sequence['owner']];
            }
        }
        foreach ($groups as $index => $group) {
            if ($index === 0) {
                continue;
            }
            foreach ($group as $member) {
                $predecessor[$member] ??= [$groups[$index - 1][0], null];
            }
        }

        $at = $sequences[0]['items'][0] ?? $groups[0][0];
        $visited = [];
        while (!isset($visited[$at])) {
            $visited[$at] = \count($visited);
            $at = $predecessor[$at][0] ?? throw new LogicException('A stuck C3 merge must contain a constraint cycle.');
        }

        $steps = [];
        $reached = [];
        $current = $at;
        do {
            [$above, $owner] = $predecessor[$current];
            $steps[] = self::describeEdge($above, $current, $owner === null ? null : $memo[$owner], $key, $origin, $position);
            $reached[] = $origin($current)->describe();
            $current = $above;
        } while ($current !== $at);

        $label = static fn(string $file): string => self::label($file, $origin);

        return ConfigurationRefusal::at(
            $origin($key),
            RefusedPosition::open([ConfigurationRoot::Imports->value], ConfigurationRoot::Imports->value),
            \sprintf(
                'The imports of %s cannot be ordered: the files it builds on demand contradictory precedence (%s). The files were reached as %s. Reorder the entries or drop one of them.',
                $label($key),
                implode('; ', array_reverse($steps)),
                implode('; ', array_reverse($reached)),
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
