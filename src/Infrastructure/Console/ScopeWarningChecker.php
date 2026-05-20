<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Configuration\Discovery\ComposerReader;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use RuntimeException;

/**
 * Checks whether the analyzed paths cover the full project scope.
 *
 * When coupling/instability metrics are computed on a subset of the project,
 * they may be inaccurate because afferent couplings from unanalyzed code are invisible.
 */
final class ScopeWarningChecker
{
    /**
     * Returns warning messages about incomplete analysis scope.
     *
     * @param AbsolutePath $projectRoot Project root directory
     * @param list<AbsolutePath> $analyzedPaths Paths being analyzed
     *
     * @return list<string> Warning messages (empty if scope is complete)
     */
    public function check(AbsolutePath $projectRoot, array $analyzedPaths): array
    {
        $composerJsonPath = $projectRoot->joinRelative(RelativePath::fromString('composer.json'));

        if (!$composerJsonPath->exists()) {
            // Missing composer.json is already reported by CheckCommand::warnIfComposerJsonMissing()
            return [];
        }

        $reader = new ComposerReader();
        // Only check production autoload paths; autoload-dev (tests/) is not required for accurate coupling metrics
        $autoloadPaths = $reader->extractAutoloadPaths($composerJsonPath->value(), includeDev: false);

        if ($autoloadPaths === []) {
            return [];
        }

        $resolvedAnalyzed = [];
        foreach ($analyzedPaths as $path) {
            try {
                $resolvedAnalyzed[] = $path->canonicalize();
            } catch (RuntimeException) {
                // Best-effort coverage check: a non-existent analyzed path is
                // already a separate, more relevant error reported by the
                // discovery layer, so silently skipping it here is intentional.
                continue;
            }
        }

        $uncoveredPaths = [];
        foreach ($autoloadPaths as $autoloadPath) {
            try {
                $resolvedAutoload = PathFactory::fromCliArgument($autoloadPath, $projectRoot)
                    ->canonicalize();
            } catch (RuntimeException) {
                // Autoload directory doesn't exist on disk — skip
                continue;
            }

            if (!$this->isCoveredByAny($resolvedAutoload, $resolvedAnalyzed, $projectRoot)) {
                $uncoveredPaths[] = $autoloadPath;
            }
        }

        if ($uncoveredPaths === []) {
            return [];
        }

        return [\sprintf(
            'Analyzed paths do not cover all autoload entries (missing: %s). Coupling and instability metrics may be incomplete.',
            implode(', ', $uncoveredPaths),
        )];
    }

    /**
     * Checks if the autoload path is covered by any of the analyzed paths.
     *
     * @param list<AbsolutePath> $analyzedPaths Canonicalized analyzed paths
     */
    private function isCoveredByAny(AbsolutePath $autoload, array $analyzedPaths, AbsolutePath $projectRoot): bool
    {
        try {
            $resolvedRoot = $projectRoot->canonicalize();
        } catch (RuntimeException) {
            // Fall back to non-canonicalized comparison when the project root
            // cannot be resolved (e.g., tested with a synthetic in-memory root).
            $resolvedRoot = null;
        }

        foreach ($analyzedPaths as $analyzed) {
            // Analyzed path equals the project root — covers everything
            if ($resolvedRoot !== null && $analyzed->equals($resolvedRoot)) {
                return true;
            }

            // Exact match. This branch MUST stay before tryRelativizeTo() — that
            // method returns null when the paths are identical (see AbsolutePath
            // contract), so equal paths would otherwise fall through as
            // "not covered".
            if ($analyzed->equals($autoload)) {
                return true;
            }

            // Autoload path is under the analyzed directory
            if ($autoload->tryRelativizeTo($analyzed) !== null) {
                return true;
            }
        }

        return false;
    }
}
