<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use RuntimeException;

/**
 * Whether a run looked at the whole project or at a slice of it.
 *
 * The denominator is the project's **production** autoload roots, not the
 * project root: `qmx check src/` on a repository whose `composer.json`
 * autoloads `src/` is a whole-project run even though the repository holds
 * `tests/`, `scripts/` and `website/` besides. `autoload-dev` is excluded for
 * the same reason the coupling warning excludes it — test code is not part of
 * the graph the metrics are about.
 *
 * **Why anything asks.** A statement that a configured value bound to nothing
 * is a statement about the pair (configuration, run scope), never about the
 * configuration alone. Measured on this tree: `qmx check
 * src/Analysis/Evidence/Cohesion/` under the project's own `qmx.yaml` reports
 * three of the four `coupling.frameworkNamespaces` prefixes as unmatched,
 * although every one of them binds on `qmx check src/` — the framework code
 * simply is not in that slice. A channel of that shape must ask this class
 * before it speaks, or it reports the user's choice of path as the author's
 * mistake.
 *
 * **The boundary of the claim, stated rather than discovered later.** This
 * class sees narrowing by *path* only. It does not see `--exclude`,
 * `exclude:` or `suppress_*` removing files from a run whose paths do cover
 * the project, and it answers "covers" when `composer.json` is absent or
 * declares no production autoload — there is no denominator then, and an
 * oracle that guessed would silence the channel on every project without a
 * composer manifest.
 */
final readonly class ProjectScopeCoverage
{
    public function __construct(private ComposerAutoloadPathReaderInterface $composerReader) {}

    /** @param list<AbsolutePath> $analyzedPaths */
    public function pathsCoverProjectScope(AbsolutePath $projectRoot, array $analyzedPaths): bool
    {
        return $this->uncoveredAutoloadRoots($projectRoot, $analyzedPaths) === [];
    }

    /**
     * The production autoload roots no analysed path contains, in the spelling
     * `composer.json` uses.
     *
     * @param list<AbsolutePath> $analyzedPaths
     *
     * @return list<string>
     */
    public function uncoveredAutoloadRoots(AbsolutePath $projectRoot, array $analyzedPaths): array
    {
        $composerJsonPath = $projectRoot->joinRelative(RelativePath::fromString('composer.json'));

        if (!$composerJsonPath->exists()) {
            // Missing composer.json is already reported by CheckCommand::warnIfComposerJsonMissing()
            return [];
        }

        // Only check production autoload paths; autoload-dev (tests/) is not required for accurate coupling metrics
        $autoloadPaths = $this->composerReader->extractAutoloadPaths($composerJsonPath->value(), includeDev: false);

        if ($autoloadPaths === []) {
            return [];
        }

        $resolvedAnalyzed = [];
        foreach ($analyzedPaths as $path) {
            // Best-effort coverage check: a non-existent analyzed path is
            // already a separate, more relevant error reported by the
            // discovery layer, so silently skipping it here is intentional.
            $resolved = $this->tryResolve(static fn(): AbsolutePath => $path->canonicalize());

            if ($resolved !== null) {
                $resolvedAnalyzed[] = $resolved;
            }
        }

        $uncoveredPaths = [];
        foreach ($autoloadPaths as $autoloadPath) {
            // Autoload directory doesn't exist on disk — skip
            $resolvedAutoload = $this->tryResolve(
                static fn(): AbsolutePath => PathFactory::fromCliArgument($autoloadPath, $projectRoot)->canonicalize(),
            );

            if ($resolvedAutoload !== null && !$this->isCoveredByAny($resolvedAutoload, $resolvedAnalyzed, $projectRoot)) {
                $uncoveredPaths[] = $autoloadPath;
            }
        }

        return $uncoveredPaths;
    }

    /**
     * Checks if the autoload path is covered by any of the analyzed paths.
     *
     * @param list<AbsolutePath> $analyzedPaths Canonicalized analyzed paths
     */
    private function isCoveredByAny(AbsolutePath $autoload, array $analyzedPaths, AbsolutePath $projectRoot): bool
    {
        // Fall back to non-canonicalized comparison when the project root
        // cannot be resolved (e.g., tested with a synthetic in-memory root).
        $resolvedRoot = $this->tryResolve(static fn(): AbsolutePath => $projectRoot->canonicalize());

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

    /**
     * Runs a path-resolving closure, letting a genuine configuration refusal
     * propagate while treating any other {@see RuntimeException} (a path that
     * does not exist on disk) as "not resolvable" rather than fatal.
     *
     * @param callable(): AbsolutePath $resolve
     */
    private function tryResolve(callable $resolve): ?AbsolutePath
    {
        try {
            return $resolve();
        } catch (ConfigurationRefusal $e) {
            throw $e;
        } catch (RuntimeException) {
            return null;
        }
    }
}
