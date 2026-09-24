<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

/**
 * Renders the incomplete-scope warning from a coverage answer already taken.
 *
 * When coupling/instability metrics are computed on a subset of the project,
 * they may be inaccurate because afferent couplings from unanalyzed code are
 * invisible.
 *
 * The coverage question belongs to
 * {@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage} and is
 * asked once by {@see CheckScopeResolver}, which needs the same answer as a
 * boolean for the run configuration. Asking it again here would read
 * `composer.json` twice and give the warning a second chance to disagree with
 * the findings about whether the run was a slice.
 */
final class ScopeWarningChecker
{
    /**
     * The two lines are independent: a whole-project run covers every counted
     * target and can still have dropped declared ones from the count.
     *
     * @param list<string> $uncoveredAutoloadRoots Production autoload targets (roots, classmap and files entries) no analyzed path contains
     * @param list<array{target: string, directory: string}> $prunedTargets Declared targets under a directory discovery never enters, and that directory
     *
     * @return list<string> Warning messages (empty when the scope is complete)
     */
    public function describe(array $uncoveredAutoloadRoots, array $prunedTargets = []): array
    {
        $warnings = [];
        if ($uncoveredAutoloadRoots !== []) {
            $warnings[] = \sprintf(
                'Analyzed paths do not cover all autoload entries (missing: %s). Coupling and instability metrics may be incomplete.',
                implode(', ', $uncoveredAutoloadRoots),
            );
        }

        if ($prunedTargets !== []) {
            $warnings[] = \sprintf(
                'Autoload entries that are, or lie inside, a vendor, node_modules or .git directory are not counted as project scope, and discovery skips them unless a path you name lies inside that directory: %s.',
                implode(', ', array_map(
                    static fn(array $pruned): string => $pruned['target'] === $pruned['directory']
                        ? $pruned['target']
                        : \sprintf('%s (inside %s)', $pruned['target'], $pruned['directory']),
                    $prunedTargets,
                )),
            );
        }

        return $warnings;
    }
}
