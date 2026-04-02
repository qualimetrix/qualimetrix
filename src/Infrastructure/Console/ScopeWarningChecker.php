<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Configuration\Discovery\ComposerReader;

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
     * @param string $workingDirectory Project working directory
     * @param list<string> $analyzedPaths Paths being analyzed
     *
     * @return list<string> Warning messages (empty if scope is complete)
     */
    public function check(string $workingDirectory, array $analyzedPaths): array
    {
        $composerJsonPath = $workingDirectory . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return ['No composer.json found. Coupling metrics require running from the project root.'];
        }

        $reader = new ComposerReader();
        // Only check production autoload paths; autoload-dev (tests/) is not required for accurate coupling metrics
        $autoloadPaths = $reader->extractAutoloadPaths($composerJsonPath, includeDev: false);

        if ($autoloadPaths === []) {
            return [];
        }

        $absoluteAnalyzedPaths = [];
        foreach ($analyzedPaths as $path) {
            $absPath = $this->toAbsolute($path, $workingDirectory);
            $resolved = realpath($absPath);
            if ($resolved !== false) {
                $absoluteAnalyzedPaths[] = $resolved;
            }
        }

        $uncoveredPaths = [];
        foreach ($autoloadPaths as $autoloadPath) {
            $absAutoload = $this->toAbsolute($autoloadPath, $workingDirectory);
            $resolvedAutoload = realpath($absAutoload);

            if ($resolvedAutoload === false) {
                // Directory doesn't exist — skip
                continue;
            }

            if (!$this->isCoveredByAny($resolvedAutoload, $absoluteAnalyzedPaths, $workingDirectory)) {
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

    private function toAbsolute(string $path, string $workingDirectory): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $workingDirectory . '/' . $path;
    }

    /**
     * Checks if the autoload path is covered by any of the analyzed paths.
     *
     * @param string $resolvedAutoload Absolute resolved autoload path
     * @param list<string> $absoluteAnalyzedPaths Absolute resolved analyzed paths
     * @param string $workingDirectory Project working directory
     */
    private function isCoveredByAny(string $resolvedAutoload, array $absoluteAnalyzedPaths, string $workingDirectory): bool
    {
        $resolvedWorkDir = realpath($workingDirectory);

        foreach ($absoluteAnalyzedPaths as $absAnalyzed) {
            // Analyzed path is '.' or equals the working directory — covers everything
            if ($resolvedWorkDir !== false && $absAnalyzed === $resolvedWorkDir) {
                return true;
            }

            // Exact match
            if ($absAnalyzed === $resolvedAutoload) {
                return true;
            }

            // Analyzed path is a parent of the autoload path
            if (str_starts_with($resolvedAutoload . '/', $absAnalyzed . '/')) {
                return true;
            }
        }

        return false;
    }
}
