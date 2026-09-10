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
     * @param list<string> $uncoveredAutoloadRoots Production autoload roots no analyzed path contains
     *
     * @return list<string> Warning messages (empty when the scope is complete)
     */
    public function describe(array $uncoveredAutoloadRoots): array
    {
        if ($uncoveredAutoloadRoots === []) {
            return [];
        }

        return [\sprintf(
            'Analyzed paths do not cover all autoload entries (missing: %s). Coupling and instability metrics may be incomplete.',
            implode(', ', $uncoveredAutoloadRoots),
        )];
    }
}
