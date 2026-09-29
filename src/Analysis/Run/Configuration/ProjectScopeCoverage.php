<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReason;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReasonKind;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
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
    public function measure(AbsolutePath $projectRoot, array $analyzedPaths, AutoloadDevPolicy $autoloadDev, PathsAuthorship $authorship): ProjectScopeMeasurement
    {
        $facts = $this->composerReader->read($projectRoot);
        $targets = $autoloadDev->projectTargets($facts->productionTargets(), $facts->developmentTargets()) ?? [];
        [$reachable, $pruned] = self::partition($projectRoot, $targets);
        $reasons = $this->sourceReasons($facts, $autoloadDev, $pruned);
        $denominator = $this->resolveDenominator($reachable, $projectRoot, $reasons);
        $this->refuseUnavailableDefaults($facts, $targets, $reachable, $autoloadDev, $authorship);
        $root = $this->tryResolve(static fn(): AbsolutePath => $projectRoot->canonicalize()) ?? $projectRoot;
        $this->addUniverseReason($facts, $autoloadDev, $reachable, $reasons);
        $universe = new ProjectScopeUniverse(
            $root,
            $authorship === PathsAuthorship::Authored,
            $denominator,
            $pruned,
            $reasons,
            $facts->state === ManifestReadState::Read && $facts->psr4Roots() !== [],
            $this->captureResolutions($projectRoot, $root, $analyzedPaths),
        );
        $uncovered = $universe->uncoveredBy($analyzedPaths);

        return new ProjectScopeMeasurement($universe, $analyzedPaths, $this->initialState($facts, $universe, $analyzedPaths, $autoloadDev, $reachable), $uncovered);
    }

    private function selectedComplete(ComposerManifestFacts $facts, AutoloadDevPolicy $autoloadDev): bool
    {
        return $facts->state === ManifestReadState::Read && $facts->production->complete
            && ($autoloadDev === AutoloadDevPolicy::Exclude || $facts->development->complete);
    }

    /**
     * @param list<array{target: string, directory: string}> $pruned
     *
     * @return list<ProjectScopeReason>
     */
    private function sourceReasons(ComposerManifestFacts $facts, AutoloadDevPolicy $autoloadDev, array $pruned): array
    {
        $issues = $autoloadDev === AutoloadDevPolicy::Include ? $facts->allScopeIssues() : $facts->productionScopeIssues();
        $reasons = array_map(ProjectScopeReason::mainManifest(...), $issues);
        foreach ($pruned as $target) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::PrunedTarget, $target);
        }

        return $reasons;
    }

    /**
     * @param list<string> $reachable
     * @param list<ProjectScopeReason> $reasons
     *
     * @return list<array{target: string, path: AbsolutePath}>
     */
    private function resolveDenominator(array $reachable, AbsolutePath $projectRoot, array &$reasons): array
    {
        $denominator = [];
        foreach ($reachable as $target) {
            $resolved = $this->tryResolve(static fn(): AbsolutePath => PathFactory::fromCliArgument($target, $projectRoot)->canonicalize());
            if ($resolved === null) {
                $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::MissingTarget, ['target' => $target]);
            } else {
                $denominator[] = ['target' => $target, 'path' => $resolved];
            }
        }

        return $denominator;
    }

    /**
     * @param list<string> $targets
     * @param list<string> $reachable
     */
    private function refuseUnavailableDefaults(ComposerManifestFacts $facts, array $targets, array $reachable, AutoloadDevPolicy $autoloadDev, PathsAuthorship $authorship): void
    {
        if ($authorship === PathsAuthorship::Authored) {
            return;
        }
        if (($facts->state !== ManifestReadState::Read && $facts->state !== ManifestReadState::Absent)
            || ($targets === [] && !$this->selectedComplete($facts, $autoloadDev) && $facts->state !== ManifestReadState::Absent)
            || ($targets !== [] && $reachable === [])) {
            throw ConfigurationRefusal::aboutDocument(ConfigurationOrigin::of(ConfigurationSource::ComposerJson, $facts->source()), 'Cannot infer analysis paths from composer.json: its selected autoload universe is unreadable or has no usable targets. Write explicit paths to analyse.');
        }
    }

    /**
     * @param list<string> $reachable
     * @param list<ProjectScopeReason> $reasons
     */
    private function addUniverseReason(ComposerManifestFacts $facts, AutoloadDevPolicy $autoloadDev, array $reachable, array &$reasons): void
    {
        if (!$this->selectedComplete($facts, $autoloadDev) && $facts->state !== ManifestReadState::Absent) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::IncompleteUniverse, ['source' => $facts->source()]);
        } elseif ($reachable === []) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::NoDeclaredCode, ['source' => $facts->source()]);
        }
    }

    /**
     * @param list<AbsolutePath> $paths
     * @param list<string> $reachable
     */
    private function initialState(ComposerManifestFacts $facts, ProjectScopeUniverse $universe, array $paths, AutoloadDevPolicy $autoloadDev, array $reachable): ProjectScopeState
    {
        $damaged = !$this->selectedComplete($facts, $autoloadDev) && $facts->state !== ManifestReadState::Absent;
        if ($damaged || $reachable === []) {
            if ($universe->containsProjectRoot($paths) && (!$damaged || $universe->pathsAuthored)) {
                return ProjectScopeState::Unknown;
            }

            return ProjectScopeState::Unmeasured;
        }

        return $universe->uncoveredBy($paths) === [] ? ProjectScopeState::Covered : ProjectScopeState::Narrowed;
    }

    /**
     * @param list<AbsolutePath> $paths
     *
     * @return list<array{written: AbsolutePath, path: AbsolutePath}>
     */
    private function captureResolutions(AbsolutePath $writtenRoot, AbsolutePath $root, array $paths): array
    {
        $resolutions = [['written' => $writtenRoot, 'path' => $root]];
        foreach ($paths as $path) {
            $resolutions[] = ['written' => $path, 'path' => $this->tryResolve(static fn(): AbsolutePath => $path->canonicalize()) ?? $path];
        }
        usort($resolutions, static fn(array $a, array $b): int => \strlen($b['written']->value()) <=> \strlen($a['written']->value()));

        return $resolutions;
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
