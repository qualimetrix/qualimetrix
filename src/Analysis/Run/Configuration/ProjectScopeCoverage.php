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
 * the project; a value whose own subject may lie outside the analysed paths
 * is judged by the per-value question its own channel asks instead, and this answer is the
 * project-wide half of that pair.
 *
 * **Three answers, not two, because "no denominator" is not "covered".**
 * A `composer.json` that declares production code through `classmap`,
 * `psr-0` or `files` leaves that code out of everything measurable here, and
 * answering "covers" there hands every scope-conditioned channel a licence to
 * accuse on a run nobody could judge — the error in the expensive direction.
 * Such a project is `Unknown`, and `Unknown` reads as "cannot judge": the
 * gate is closed and no scope warning is printed, because there is no
 * uncovered root to name.
 *
 * **An unread section beside a readable one is the same "no denominator", not
 * a smaller one.** A manifest declaring both `psr-4` and `classmap` yields a
 * non-empty root list, and measuring against it answers about the PSR-4 half
 * while the classmap half — production code, never analysed, never counted —
 * is silently absent from both numerator and denominator. Emptiness of the
 * root list is therefore not the question; whether anything production was
 * declared that this class cannot measure is. `autoload-dev` sections and
 * `exclude-from-classmap` are not such declarations: the first is test code,
 * outside the denominator by design, and the second removes code rather than
 * declaring it.
 *
 * A *missing* `composer.json` stays "covers": there
 * is no project manifest to narrow against at all, the absence is already
 * reported by `CheckCommand::warnIfComposerJsonMissing()`, and treating it as
 * `Unknown` would silence these channels on every project that has none.
 */
final readonly class ProjectScopeCoverage
{
    public function __construct(private ComposerAutoloadPathReaderInterface $composerReader) {}

    /** @param list<AbsolutePath> $analyzedPaths */
    public function pathsCoverProjectScope(AbsolutePath $projectRoot, array $analyzedPaths): bool
    {
        return $this->measure($projectRoot, $analyzedPaths)->covers();
    }

    /**
     * The production autoload roots no analysed path contains, in the spelling
     * `composer.json` uses.
     *
     * Empty on a project whose production autoload this class cannot read:
     * there is no root to name, which is why the verdict and this list are
     * taken from one measurement — a caller reading emptiness here as "covers"
     * would reintroduce exactly the answer {@see ProjectScopeMeasurement}
     * separates.
     *
     * @param list<AbsolutePath> $analyzedPaths
     *
     * @return list<string>
     */
    public function uncoveredAutoloadRoots(AbsolutePath $projectRoot, array $analyzedPaths): array
    {
        return $this->measure($projectRoot, $analyzedPaths)->uncoveredRoots;
    }

    /**
     * The one measurement both answers above are read from.
     *
     * @param list<AbsolutePath> $analyzedPaths
     */
    public function measure(AbsolutePath $projectRoot, array $analyzedPaths): ProjectScopeMeasurement
    {
        $composerJsonPath = $projectRoot->joinRelative(RelativePath::fromString('composer.json'));

        if (!$composerJsonPath->exists()) {
            // Missing composer.json is already reported by CheckCommand::warnIfComposerJsonMissing()
            return ProjectScopeMeasurement::covered();
        }

        // A section this product cannot read declares production code that
        // would never enter the denominator, so the measurement is short by
        // it whether or not a psr-4 section stands beside it.
        if ($this->composerReader->declaresUnreadableProductionAutoload($composerJsonPath->value())) {
            return ProjectScopeMeasurement::unreadable();
        }

        // Only check production autoload paths; autoload-dev (tests/) is not required for accurate coupling metrics
        $autoloadPaths = $this->composerReader->extractAutoloadPaths($composerJsonPath->value(), includeDev: false);

        if ($autoloadPaths === []) {
            return ProjectScopeMeasurement::unreadable();
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

        return ProjectScopeMeasurement::against($uncoveredPaths);
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
