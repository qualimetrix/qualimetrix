<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

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
use Qualimetrix\Core\Path\AbsolutePath;

/** Measures the selected code universe once; subsequent Git narrowing uses its captured denominator. */
final readonly class ProjectScopeCoverage
{
    public function __construct(private ComposerManifestReaderInterface $composerReader) {}

    /** @param list<AbsolutePath> $analyzedPaths */
    public function measure(AbsolutePath $projectRoot, array $analyzedPaths, AutoloadDevPolicy $autoloadDev, PathsAuthorship $authorship): ProjectScopeMeasurement
    {
        $facts = $this->composerReader->read($projectRoot);
        $targets = $autoloadDev->projectTargets($facts->productionTargets(), $facts->developmentTargets()) ?? [];
        [$reachable, $pruned] = ProjectScopePaths::partition($projectRoot, $targets);
        $reasons = $this->sourceReasons($facts, $autoloadDev, $pruned);
        $denominator = ProjectScopePaths::resolveDenominator($reachable, $projectRoot, $reasons);
        ProjectScopeDefaults::refuseUnavailable($facts, $targets, $reachable, $autoloadDev, $authorship);
        $root = ProjectScopePaths::canonicalRoot($projectRoot);
        $this->addUniverseReason($facts, $autoloadDev, $reachable, $reasons);
        $universe = new ProjectScopeUniverse(
            $root,
            $authorship === PathsAuthorship::Authored,
            $denominator,
            $pruned,
            $reasons,
            $facts->state === ManifestReadState::Read && $facts->psr4Roots() !== [],
            ProjectScopePaths::captureResolutions($projectRoot, $root, $analyzedPaths),
        );
        $uncovered = $universe->uncoveredBy($analyzedPaths);

        return new ProjectScopeMeasurement($universe, $analyzedPaths, $this->initialState($facts, $universe, $analyzedPaths, $autoloadDev, $reachable), $uncovered);
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
     */
    private function addUniverseReason(ComposerManifestFacts $facts, AutoloadDevPolicy $autoloadDev, array $reachable, array &$reasons): void
    {
        if (!ProjectScopeDefaults::selectedComplete($facts, $autoloadDev) && $facts->state !== ManifestReadState::Absent) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::IncompleteUniverse, ['source' => 'composer.json']);
        } elseif ($reachable === []) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::NoDeclaredCode, ['source' => 'composer.json']);
        }
    }

    /**
     * @param list<AbsolutePath> $paths
     * @param list<string> $reachable
     */
    private function initialState(ComposerManifestFacts $facts, ProjectScopeUniverse $universe, array $paths, AutoloadDevPolicy $autoloadDev, array $reachable): ProjectScopeState
    {
        $damaged = !ProjectScopeDefaults::selectedComplete($facts, $autoloadDev) && $facts->state !== ManifestReadState::Absent;
        if ($damaged || $reachable === []) {
            if ($universe->containsProjectRoot($paths) && (!$damaged || $universe->pathsAuthored)) {
                return ProjectScopeState::Unknown;
            }

            return ProjectScopeState::Unmeasured;
        }

        return $universe->uncoveredBy($paths) === [] ? ProjectScopeState::Covered : ProjectScopeState::Narrowed;
    }
}
