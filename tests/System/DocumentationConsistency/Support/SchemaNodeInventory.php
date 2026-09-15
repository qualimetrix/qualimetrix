<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\System\DocumentationConsistency\Support;

/**
 * Walks an observed report and names every schema node in it.
 *
 * This is the guard's *enumeration tool*, not its oracle: it says what the
 * product emits, and the page is still what that gets compared against. The
 * distinction matters because the walker's own judgement — where it stops — is
 * a constant anyone can read and argue with, rather than a decision buried in
 * a comparison.
 *
 * A node's key set has two halves, because one array can hold entries of
 * different shapes. Measured on one SARIF run: 6 results carry `locations` and
 * 9 do not, which is exactly what the page publishes ("`locations` is not
 * present on every result"). Collapsing that into a single set loses the
 * statement either way — a union makes the guard green on a run where nothing
 * has `locations`, an intersection makes it red against prose that is right.
 * So `required` is the intersection over entries and `optional` is what only
 * some of them carry.
 */
final class SchemaNodeInventory
{
    /**
     * Maps whose keys are data — rule names, metric names, mechanism names —
     * and whose values are scalars. Nothing below them is schema.
     *
     * @var list<string>
     */
    public const array DATA_KEYED_SCALAR_PATHS = [
        'violationsMeta.byRule',
        'byMechanism',
        'symbols[].metrics',
        'worstNamespaces[].healthScores',
        'worstClasses[].healthScores',
        'worstNamespaces[].metrics',
        'worstClasses[].metrics',
        'health.{}.worstContributors[].metrics',
    ];

    /**
     * Maps whose keys are data but whose *values* carry a schema the page
     * publishes: `health` is keyed by dimension yet each value is
     * `{score, label, threshold, decomposition}`, and `violationGroups` is
     * keyed by class or namespace yet each value is `{count, violations}`.
     *
     * Treating these like the scalar maps above would drop two documented
     * shapes on the floor, so their values are walked under a `{}` segment
     * while their keys stay unexamined.
     *
     * @var list<string>
     */
    public const array DATA_KEYED_OBJECT_PATHS = [
        'health',
        'violationGroups',
    ];

    /**
     * Distinct paths that carry the same shape, and so are one node.
     *
     * A grouped run nests the very same violation entries under each group
     * key; documenting them a second time would only create a second place to
     * forget. `health.{}.worstContributors[].metrics` is keyed by metric name
     * and belongs with the other data-keyed maps.
     *
     * @var array<string, string>
     */
    public const array NODE_ALIASES = [
        'violationGroups.{}.violations[]' => 'violations[]',
        'violationGroups.{}.violations[].edge' => 'violations[].edge',
        'violationGroups.{}.violations[].acceptedLevel' => 'violations[].acceptedLevel',
    ];

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, array{required: list<string>, optional: list<string>}> node path => key halves
     */
    public static function of(array $report): array
    {
        /** @var array<string, list<list<string>>> $observed */
        $observed = [];
        self::walk($report, '', $observed);

        ksort($observed);

        return array_map(
            static function (array $entries): array {
                $required = array_values(array_intersect(...$entries));
                $union = array_values(array_unique(array_merge(...$entries)));
                sort($required);
                $optional = array_values(array_diff($union, $required));
                sort($optional);

                return ['required' => $required, 'optional' => $optional];
            },
            $observed,
        );
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, list<list<string>>> $observed
     */
    private static function walk(array $value, string $path, array &$observed): void
    {
        if ($value === []) {
            // An empty list and an empty object are indistinguishable once
            // decoded, and neither carries a key set. Recording one would add a
            // keyless node that no statement can ever bind.
            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $entry) {
                if (\is_array($entry)) {
                    self::walk($entry, $path . '[]', $observed);
                }
            }

            return;
        }

        $nodePath = $path === '' ? '(root)' : $path;
        $nodePath = self::NODE_ALIASES[$nodePath] ?? $nodePath;
        $observed[$nodePath][] = array_map(strval(...), array_keys($value));

        foreach ($value as $key => $child) {
            if (!\is_array($child)) {
                continue;
            }

            $childPath = $path === '' ? (string) $key : $path . '.' . $key;

            if (\in_array($childPath, self::DATA_KEYED_SCALAR_PATHS, true)) {
                continue;
            }

            if (\in_array($childPath, self::DATA_KEYED_OBJECT_PATHS, true)) {
                foreach ($child as $grandchild) {
                    if (\is_array($grandchild)) {
                        self::walk($grandchild, $childPath . '.{}', $observed);
                    }
                }

                continue;
            }

            self::walk($child, $childPath, $observed);
        }
    }
}
