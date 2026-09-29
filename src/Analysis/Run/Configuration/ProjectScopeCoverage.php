<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReason;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReasonKind;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use RuntimeException;

/** Measures the selected code universe once; subsequent Git narrowing uses its captured denominator. */
final readonly class ProjectScopeCoverage
{
    /**
     * Every channel that is silent on a `Narrowed` run because it reads a
     * {@see ProjectScopeMeasurement::state()} answer, directly or through the
     * answer a run configuration or rule context carries — a report names them as not
     * judged. Only the Architecture names come from their owner's contract:
     * the others are declared on classes internal to their capability, and
     * importing Discovery's own would tie this namespace to the one it gates.
     * A reader added without its channels here reddens `ProjectScopeReadersTest`.
     *
     * @var list<string>
     */
    public const array WHOLE_PROJECT_CHANNELS = [
        LayerPolicyPreparationInterface::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
        LayerPolicyPreparationInterface::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME,
        LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
        'coupling.unmatched-framework-namespace',
        'discovery.unmatched-exclude',
        'suppression.unmatched-namespace',
        'suppression.unmatched-path',
        'suppression.unmatched-rule-ledger',
    ];

    public function __construct(private ComposerManifestReaderInterface $composerReader) {}

    /** @param list<AbsolutePath> $analyzedPaths */
    public function measure(AbsolutePath $projectRoot, array $analyzedPaths, AutoloadDevPolicy $autoloadDev, bool $pathsAuthored): ProjectScopeMeasurement
    {
        $facts = $this->composerReader->read($projectRoot);
        $includeDev = $autoloadDev === AutoloadDevPolicy::Include;
        $complete = $facts->state === ManifestReadState::Read && $facts->productionComplete && (!$includeDev || $facts->developmentComplete);
        $targets = $autoloadDev->projectTargets($facts->productionTargets(), $facts->developmentTargets()) ?? [];
        [$reachable, $pruned] = self::partition($projectRoot, $targets);
        $reasons = array_map(static fn($issue): ProjectScopeReason => ProjectScopeReason::manifest($issue, false), $facts->scopeIssues($includeDev));
        foreach ($pruned as $target) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::PrunedTarget, $target);
        }

        $denominator = [];
        foreach ($reachable as $target) {
            $resolved = $this->tryResolve(static fn(): AbsolutePath => PathFactory::fromCliArgument($target, $projectRoot)->canonicalize());
            if ($resolved === null) {
                $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::MissingTarget, ['target' => $target]);
            } else {
                $denominator[] = ['target' => $target, 'path' => $resolved];
            }
        }

        if (!$pathsAuthored && (($facts->state !== ManifestReadState::Read && $facts->state !== ManifestReadState::Absent)
            || ($targets === [] && !$complete && $facts->state !== ManifestReadState::Absent)
            || ($targets !== [] && $reachable === []))) {
            throw ConfigurationRefusal::aboutDocument(ConfigurationOrigin::of(ConfigurationSource::ComposerJson, $facts->source()), 'Cannot infer analysis paths from composer.json: its selected autoload universe is unreadable or has no usable targets. Write explicit paths to analyse.');
        }

        $root = $this->tryResolve(static fn(): AbsolutePath => $projectRoot->canonicalize()) ?? $projectRoot;
        $paths = array_map(fn(AbsolutePath $path): AbsolutePath => $this->tryResolve(static fn(): AbsolutePath => $path->canonicalize()) ?? $path, $analyzedPaths);
        $pathResolutions = [['written' => $projectRoot, 'path' => $root]];
        foreach ($analyzedPaths as $index => $written) {
            $pathResolutions[] = ['written' => $written, 'path' => $paths[$index]];
        }
        usort($pathResolutions, static fn(array $a, array $b): int => \strlen($b['written']->value()) <=> \strlen($a['written']->value()));
        $uncovered = self::uncovered($denominator, $paths, $root);
        $damaged = !$complete && $facts->state !== ManifestReadState::Absent;
        if ($damaged || $reachable === []) {
            $reasons[] = new ProjectScopeReason($damaged ? ProjectScopeReasonKind::IncompleteUniverse : ProjectScopeReasonKind::NoDeclaredCode, ['source' => $facts->source()]);
            $wholeRoot = self::containsRoot($paths, $root);
            $state = $wholeRoot && (!$damaged || $pathsAuthored) ? ProjectScopeState::Unknown : ProjectScopeState::Unmeasured;
        } else {
            $state = $uncovered === [] ? ProjectScopeState::Covered : ProjectScopeState::Narrowed;
        }

        return new ProjectScopeMeasurement($root, $analyzedPaths, $pathsAuthored, $state, $denominator, $uncovered, $pruned, $reasons, $facts->state === ManifestReadState::Read && $facts->psr4Roots() !== [], $pathResolutions);
    }

    /**
     * Pure intersection with the already measured universe. A wider final path cannot reopen a closed gate.
     *
     * @param list<AbsolutePath> $finalPaths
     */
    public static function narrow(ProjectScopeMeasurement $initial, array $finalPaths): ProjectScopeMeasurement
    {
        $resolvedPaths = array_map(static fn(AbsolutePath $path): AbsolutePath => self::resolveCaptured($path, $initial->pathResolutions), $finalPaths);
        $uncovered = self::uncovered($initial->denominator, $resolvedPaths, $initial->projectRoot);
        $state = $initial->state();
        if ($state->coversProjectScope()) {
            if ($state === ProjectScopeState::Covered && $uncovered !== []) {
                $state = ProjectScopeState::Narrowed;
            } elseif ($state === ProjectScopeState::Unknown && !self::containsRoot($resolvedPaths, $initial->projectRoot)) {
                $state = ProjectScopeState::Unmeasured;
            }
        }

        return new ProjectScopeMeasurement($initial->projectRoot, $finalPaths, $initial->pathsAuthored, $state, $initial->denominator, $uncovered, $initial->prunedTargets, $initial->reasons, $initial->namespaceMapUsable, $initial->pathResolutions);
    }

    /** @param list<array{written: AbsolutePath, path: AbsolutePath}> $resolutions */
    private static function resolveCaptured(AbsolutePath $path, array $resolutions): AbsolutePath
    {
        foreach ($resolutions as $resolution) {
            if ($path->equals($resolution['written'])) {
                return $resolution['path'];
            }
            $relative = $path->tryRelativizeTo($resolution['written']);
            if ($relative !== null) {
                return $resolution['path']->joinRelative($relative);
            }
        }

        return $path;
    }

    /** @param list<AbsolutePath> $paths */
    private static function containsRoot(array $paths, AbsolutePath $root): bool
    {
        foreach ($paths as $path) {
            if ($path->equals($root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{target: string, path: AbsolutePath}> $denominator
     * @param list<AbsolutePath> $paths
     *
     * @return list<string>
     */
    private static function uncovered(array $denominator, array $paths, AbsolutePath $root): array
    {
        $uncovered = [];
        foreach ($denominator as $target) {
            $covered = self::containsRoot($paths, $root);
            foreach ($paths as $path) {
                $covered = $covered || $target['path']->equals($path) || $target['path']->tryRelativizeTo($path) !== null;
            }
            if (!$covered) {
                $uncovered[] = $target['target'];
            }
        }

        return $uncovered;
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
    public static function reachableTargets(AbsolutePath $projectRoot, array $targets): array
    {
        return self::partition($projectRoot, $targets)[0];
    }

    /**
     * @param list<string> $targets
     *
     * @return array{list<string>, list<array{target: string, directory: string}>}
     */
    private static function partition(AbsolutePath $projectRoot, array $targets): array
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
