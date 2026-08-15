<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowListEntry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;

/**
 * Rejects directed cycles in the exact-selector projection of architecture.allow.
 *
 * Exact selectors already name concrete declared layers at configuration-load
 * time, so their dependency graph must be a DAG before analysis starts. Glob
 * and captured selectors are intentionally excluded: template expansion is
 * observation-driven and produces its concrete layer names only after file
 * collection, so treating their patterns as graph nodes here would invent
 * edges that may never exist.
 *
 * Relation filters do not weaken this invariant. An exact allow edge declares
 * architectural reachability regardless of which dependency kinds exercise it;
 * opposing edges with disjoint filters still form a cyclic module boundary.
 */
final class ExactAllowCycleValidator
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * @param list<AllowListEntry> $entries
     *
     * @throws ArchitectureConfigurationException
     */
    public function validate(array $entries): void
    {
        $cycle = self::findCycle(self::projectExactGraph($entries));
        if ($cycle === null) {
            return;
        }

        throw new ArchitectureConfigurationException(
            self::CONFIG_PATH,
            \sprintf(
                'architecture.allow: directed cycle detected in exact declared layer graph: %s. '
                . 'Module dependencies must form a DAG; remove at least one allow edge from this cycle.',
                implode(' -> ', $cycle),
            ),
        );
    }

    /**
     * @param list<AllowListEntry> $entries
     *
     * @return array<string, list<string>>
     */
    private static function projectExactGraph(array $entries): array
    {
        /** @var array<string, array<string, true>> $targetSets */
        $targetSets = [];

        foreach ($entries as $entry) {
            if (!$entry->source->isExact()) {
                continue;
            }

            $source = $entry->source->originalString();
            $targetSets[$source] ??= [];

            foreach ($entry->targets as $target) {
                if (!$target->target->isExact()) {
                    continue;
                }

                $targetName = $target->target->originalString();
                $targetSets[$source][$targetName] = true;
                $targetSets[$targetName] ??= [];
            }
        }

        ksort($targetSets);
        $graph = [];
        foreach ($targetSets as $source => $targets) {
            $targetNames = array_keys($targets);
            sort($targetNames);
            $graph[$source] = $targetNames;
        }

        return $graph;
    }

    /**
     * @param array<string, list<string>> $graph
     *
     * @return list<string>|null Closed cycle path, with the first node repeated at the end.
     */
    private static function findCycle(array $graph): ?array
    {
        /** @var array<string, 1|2> $states */
        $states = [];
        /** @var list<string> $stack */
        $stack = [];
        /** @var array<string, int> $stackPositions */
        $stackPositions = [];

        foreach (array_keys($graph) as $node) {
            if (isset($states[$node])) {
                continue;
            }

            $cycle = self::visit($node, $graph, $states, $stack, $stackPositions);
            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $graph
     * @param array<string, 1|2> $states
     * @param list<string> $stack
     * @param array<string, int> $stackPositions
     *
     * @return list<string>|null
     */
    private static function visit(
        string $node,
        array $graph,
        array &$states,
        array &$stack,
        array &$stackPositions,
    ): ?array {
        $states[$node] = 1;
        $stackPositions[$node] = \count($stack);
        $stack[] = $node;

        foreach ($graph[$node] as $target) {
            if (($states[$target] ?? null) === 1) {
                $cycle = \array_slice($stack, $stackPositions[$target]);
                $cycle[] = $target;

                return $cycle;
            }

            if (($states[$target] ?? null) === 2) {
                continue;
            }

            $cycle = self::visit($target, $graph, $states, $stack, $stackPositions);
            if ($cycle !== null) {
                return $cycle;
            }
        }

        array_pop($stack);
        unset($stackPositions[$node]);
        $states[$node] = 2;

        return null;
    }
}
