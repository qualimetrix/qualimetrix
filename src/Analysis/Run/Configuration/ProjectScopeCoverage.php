<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use RuntimeException;

/**
 * Whether a run looked at the whole project or at a slice of it.
 *
 * The denominator is the project's **production** autoload targets, not the
 * project root: `qmx check src/` on a repository whose `composer.json`
 * autoloads `src/` is a whole-project run even though the repository holds
 * `tests/`, `scripts/` and `website/` besides. `autoload-dev` is excluded by
 * default for the same reason the coupling warning excludes it — test code is
 * not part of the graph the metrics are about — and included when the run's
 * {@see AutoloadDevPolicy} says the author counts it. The targets and the
 * policy are the ones a run with no `paths` analyses: the same reader answer
 * taken through {@see AutoloadDevPolicy::projectTargets()}, so a run over
 * the defaults covers what it is judged against whatever autoload form
 * declared the code.
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
 * **Two answers, and the second is "cannot judge".** A run is measured
 * against every production path the manifest declares — `psr-4` and `psr-0`
 * roots, `classmap` entries and `files` entries alike. A `classmap` or
 * `files` entry may name a single file rather than a directory, which is no
 * obstacle: the question asked of each target is whether an analysed path
 * contains it, and containment answers the same way for a file. The other
 * answer, "cannot judge", is reached only when the manifest declares nothing
 * readable *at all* — it is absent, it does not parse, it has no `autoload`
 * section, or every production section in it is empty or malformed. Then
 * there is no denominator, the gate closes, and no scope warning is printed
 * because there is no uncovered target to name.
 *
 * `classmap`, `psr-0` and `files` are ordinary production targets. Treating a
 * manifest that declares production code through any of
 * them as unjudgeable was measured to silence every scope-conditioned channel
 * on 51 of the 125 packages in `benchmarks/vendor` — most often a `files`
 * section of polyfills or helpers standing beside an ordinary `psr-4` one.
 * A cure that is itself inert on half of real projects is worse than the
 * defect it treats, because the defect is visible and the inertness is not.
 * Those sections are ordinary path targets here, and only genuine
 * illegibility closes the gate.
 *
 * **A target that does not exist on disk is skipped**, and skipping opens the
 * gate rather than closing it. A `classmap` `*` reaches this class already
 * expanded by the reader to the directories it matches; one matching nothing
 * stays as written and is skipped like any other missing target.
 *
 * **A target no walk of the project reaches is not the project's.** Discovery
 * never descends into `vendor`, `node_modules` or `.git`
 * ({@see DirectoryPruner::builtInPatterns()}), so a `files` entry inside
 * `vendor/` or a `classmap` entry that is itself a `vendor` directory names
 * code the product does not treat as project code. Such a target leaves the
 * default paths and the denominator together — both through
 * {@see self::reachableTargets()}, asking the pruner the traversal asks — and
 * the measurement names it rather than dropping it in silence. Only the
 * built-in floor is asked, not the author's `exclude:`: this class does not
 * see authored narrowing (above).
 */
final readonly class ProjectScopeCoverage
{
    public function __construct(private ComposerAutoloadPathReaderInterface $composerReader) {}

    /** @param list<AbsolutePath> $analyzedPaths */
    public function pathsCoverProjectScope(AbsolutePath $projectRoot, array $analyzedPaths, AutoloadDevPolicy $autoloadDev): bool
    {
        return $this->measure($projectRoot, $analyzedPaths, $autoloadDev)->covers();
    }

    /**
     * The autoload targets the policy counts that no analysed path
     * contains, in the spelling `composer.json` uses.
     *
     * Empty on a project whose production autoload this class cannot read:
     * there is no target to name, which is why the verdict and this list are
     * taken from one measurement — a caller reading emptiness here as "covers"
     * would reintroduce exactly the answer {@see ProjectScopeMeasurement}
     * separates.
     *
     * @param list<AbsolutePath> $analyzedPaths
     *
     * @return list<string>
     */
    public function uncoveredAutoloadRoots(AbsolutePath $projectRoot, array $analyzedPaths, AutoloadDevPolicy $autoloadDev): array
    {
        return $this->measure($projectRoot, $analyzedPaths, $autoloadDev)->uncoveredRoots;
    }

    /**
     * The one measurement both answers above are read from.
     *
     * @param list<AbsolutePath> $analyzedPaths
     */
    public function measure(AbsolutePath $projectRoot, array $analyzedPaths, AutoloadDevPolicy $autoloadDev): ProjectScopeMeasurement
    {
        $composerJsonPath = $projectRoot->joinRelative(RelativePath::fromString('composer.json'));

        // One question, one branch: either the manifest declares production
        // paths this product can compare a run against, or it declares none
        // and no channel may judge anything on this run. A missing manifest
        // is that second case. Its absence does produce a stderr line from
        // CheckCommand::warnIfComposerJsonMissing(), but that line survives
        // neither `-q` nor the machine formats, so on such a project this
        // silence is what a CI pipeline sees — the price of not guessing
        // "whole project" for a project that never said what its code is.
        [$autoloadPaths, $prunedTargets] = $this->partition(
            $projectRoot,
            $this->declaredTargets($composerJsonPath->value(), $autoloadDev) ?? [],
        );

        // `[]` is the same answer as `null` and is spelled out rather than
        // trusted away: reading an empty denominator as "covers" is precisely
        // the defect this measurement was amended to remove. A manifest whose
        // every target is pruned lands here too — it declares no code a walk
        // of the project reaches.
        if ($autoloadPaths === []) {
            return ProjectScopeMeasurement::unreadable($prunedTargets);
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
            // The declared target does not exist on disk — skip it. See the
            // class docblock: this opens the gate rather than closing it.
            $resolvedAutoload = $this->tryResolve(
                static fn(): AbsolutePath => PathFactory::fromCliArgument($autoloadPath, $projectRoot)->canonicalize(),
            );

            if ($resolvedAutoload !== null && !$this->isCoveredByAny($resolvedAutoload, $resolvedAnalyzed, $projectRoot)) {
                $uncoveredPaths[] = $autoloadPath;
            }
        }

        return ProjectScopeMeasurement::against($uncoveredPaths, $prunedTargets);
    }

    /**
     * The declared targets a walk from the project root reaches, in their
     * declared order and spelling — what a run with no `paths` analyses and
     * what this class measures a run against.
     *
     * @param list<string> $targets
     *
     * @return list<string>
     */
    public function reachableTargets(AbsolutePath $projectRoot, array $targets): array
    {
        return $this->partition($projectRoot, $targets)[0];
    }

    /**
     * @param list<string> $targets
     *
     * @return array{list<string>, list<array{target: string, directory: string}>}
     */
    private function partition(AbsolutePath $projectRoot, array $targets): array
    {
        $pruner = new DirectoryPruner($projectRoot, DirectoryPruner::builtInPatterns());

        $reachable = [];
        $pruned = [];
        foreach ($targets as $target) {
            // The empty path is refused where paths are accepted; judging it
            // here would only throw in a different vocabulary.
            $directory = $target === ''
                ? null
                : $pruner->prunedAncestor(PathFactory::fromCliArgument($target, $projectRoot));

            if ($directory === null) {
                $reachable[] = $target;
            } else {
                $pruned[] = ['target' => $target, 'directory' => $directory];
            }
        }

        return [$reachable, $pruned];
    }

    /**
     * The targets a run is measured against — the reader's whole-manifest
     * answer under the run's policy, which is also what a run with no
     * `paths` analyses.
     *
     * @return ?list<string>
     */
    private function declaredTargets(string $composerJsonPath, AutoloadDevPolicy $autoloadDev): ?array
    {
        return $autoloadDev->projectTargets(
            $this->composerReader->productionAutoloadTargets($composerJsonPath),
            $autoloadDev === AutoloadDevPolicy::Include ? $this->composerReader->developmentAutoloadTargets($composerJsonPath) : null,
        );
    }

    /**
     * Checks if the declared autoload target — a directory or a single file —
     * is covered by any of the analyzed paths.
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
