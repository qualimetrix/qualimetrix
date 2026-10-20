<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReason;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReasonKind;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Core\Path\AbsolutePath;

/** Interprets the captured Composer universe under the selected autoload policy, without IO. */
final readonly class ManifestScopeEvidence
{
    public function __construct(private ComposerManifestFacts $facts, private AutoloadDevPolicy $autoloadDev) {}

    /** @return list<string> */
    public function targets(): array
    {
        return $this->autoloadDev->projectTargets($this->facts->productionTargets(), $this->facts->developmentTargets()) ?? [];
    }

    /**
     * @param list<array{target: string, directory: string}> $pruned
     *
     * @return list<ProjectScopeReason>
     */
    public function sourceReasons(array $pruned): array
    {
        $issues = $this->autoloadDev === AutoloadDevPolicy::Include ? $this->facts->allScopeIssues() : $this->facts->productionScopeIssues();
        $reasons = array_map(ProjectScopeReason::mainManifest(...), $issues);
        foreach ($pruned as $target) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::PrunedTarget, $target);
        }

        return $reasons;
    }

    /**
     * @param list<string> $targets
     * @param list<string> $reachable
     */
    public function refuseUnavailable(array $targets, array $reachable, PathsAuthorship $authorship): void
    {
        ProjectScopeDefaults::refuseUnavailable($this->facts, $targets, $reachable, $this->autoloadDev, $authorship);
    }

    /**
     * @param list<string> $reachable
     * @param list<ProjectScopeReason> $reasons
     */
    public function addUniverseReason(array $reachable, array &$reasons): void
    {
        if ($this->damaged()) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::IncompleteUniverse, ['source' => 'composer.json']);
        } elseif ($reachable === []) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::NoDeclaredCode, ['source' => 'composer.json']);
        }
    }

    public function hasDeclaredNamespaces(): bool
    {
        return $this->facts->state === ManifestReadState::Read && $this->facts->psr4Roots() !== [];
    }

    /**
     * @param list<AbsolutePath> $paths
     * @param list<string> $reachable
     */
    public function initialState(ProjectScopeUniverse $universe, array $paths, array $reachable): ProjectScopeState
    {
        $damaged = $this->damaged();
        if ($damaged || $reachable === []) {
            if ($universe->containsProjectRoot($paths) && (!$damaged || $universe->pathsAuthored)) {
                return ProjectScopeState::Unknown;
            }

            return ProjectScopeState::Unmeasured;
        }

        return $universe->uncoveredBy($paths) === [] ? ProjectScopeState::Covered : ProjectScopeState::Narrowed;
    }

    private function damaged(): bool
    {
        return !ProjectScopeDefaults::selectedComplete($this->facts, $this->autoloadDev) && $this->facts->state !== ManifestReadState::Absent;
    }
}
