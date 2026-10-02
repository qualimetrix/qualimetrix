<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles;
use Qualimetrix\Core\Path\AbsolutePath;

/** Current selection and verdict over one captured source universe. */
final readonly class ProjectScopeMeasurement
{
    /**
     * @param list<AbsolutePath> $paths
     * @param list<string> $uncoveredRoots
     * @param list<ProjectScopeReason> $measuredReasons
     */
    public function __construct(
        public ProjectScopeUniverse $universe,
        public array $paths,
        private ProjectScopeState $scopeState,
        public array $uncoveredRoots,
        private ?ProjectScopeJudgement $judgement = null,
        private array $measuredReasons = [],
    ) {}

    public function state(): ProjectScopeState
    {
        return $this->scopeState;
    }

    public function judgement(): ProjectScopeJudgement
    {
        return $this->judgement ?? throw new LogicException('Project scope has not been measured against discovered files');
    }

    /** @return list<ProjectScopeReason> */
    public function reasons(): array
    {
        return array_values([...$this->universe->reasons, ...$this->measuredReasons]);
    }

    public function withDiscoveredFiles(DiscoveredProjectFiles $files): self
    {
        $facts = $files->scopeFacts;
        $pathsMissing = $facts->pathsNarrowed();
        $unknownDenominator = $facts->denominatorUnknown();
        $sourceIncomplete = \in_array($this->scopeState, [ProjectScopeState::Unknown, ProjectScopeState::Unmeasured], true);

        $state = match (true) {
            $unknownDenominator => ProjectScopeState::Unknown,
            $pathsMissing => ProjectScopeState::Narrowed,
            $sourceIncomplete && !$this->universe->containsProjectRoot($this->paths) => ProjectScopeState::Unmeasured,
            $sourceIncomplete => ProjectScopeState::Unknown,
            default => ProjectScopeState::Covered,
        };

        $namespaceDoors = [];
        $selectorDoors = [];
        if ($pathsMissing) {
            $namespaceDoors[] = $selectorDoors[] = ProjectScopeDoor::Paths;
        }
        if ($unknownDenominator || $state === ProjectScopeState::Unmeasured) {
            $namespaceDoors[] = $selectorDoors[] = ProjectScopeDoor::UnknownUniverse;
        }
        if ($facts->generatedExcluded > 0) {
            $namespaceDoors[] = ProjectScopeDoor::Generated;
        }

        $reasons = [];
        $selectors = [];
        foreach ($files->selectorVerdicts as $selector) {
            if ($selector->phpEvidence === 'php-file') {
                $namespaceDoors[] = ProjectScopeDoor::Exclude;
                $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::Exclude, [
                    'selector' => $selector->display,
                    'removedEntries' => \count($selector->removedEntries),
                    'evidence' => $selector->phpEvidence,
                ]);
            }
            if ($selectorDoors !== []) {
                $selector = $selector->withoutSelectorJudgement();
            }
            $selectors[] = $selector;
        }
        if ($facts->generatedExcluded > 0) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::Generated, ['removedFiles' => $facts->generatedExcluded]);
        }
        if ($facts->namedFilesOnly && $pathsMissing) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::ExplicitFiles, [
                'namedFiles' => \count($this->paths),
                'missingFiles' => \count($facts->missingByPaths),
            ]);
        }
        if ($unknownDenominator) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::UnlistableOutsidePaths, [
                'unlistable' => array_map(static fn($path): string => $path->value(), $facts->unlistableOutside),
                'hidden' => array_map(static fn($path): string => $path->value(), $facts->hiddenOutsideDirectories),
            ]);
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::IncompleteUniverse, ['source' => 'project-tree']);
        }

        return new self(
            $this->universe,
            $this->paths,
            $state,
            $pathsMissing ? array_map(static fn($path): string => $path->value(), $facts->missingByPaths) : [],
            new ProjectScopeJudgement(self::uniqueDoors($namespaceDoors), self::uniqueDoors($selectorDoors), $selectors),
            $reasons,
        );
    }

    /** @param list<ProjectScopeDoor> $doors
     * @return list<ProjectScopeDoor>
     */
    private static function uniqueDoors(array $doors): array
    {
        $unique = [];
        foreach ($doors as $door) {
            $unique[$door->value] = $door;
        }

        return array_values($unique);
    }

    /**
     * A wider final path cannot reopen a closed gate. Only captured path facts are used.
     *
     * @param list<AbsolutePath> $finalPaths
     */
    public function narrowTo(array $finalPaths): self
    {
        $uncovered = $this->universe->uncoveredBy($finalPaths);
        $state = $this->scopeState;
        if ($state->coversProjectScope()) {
            if ($state === ProjectScopeState::Covered && $uncovered !== []) {
                $state = ProjectScopeState::Narrowed;
            } elseif ($state === ProjectScopeState::Unknown && !$this->universe->containsProjectRoot($finalPaths)) {
                $state = ProjectScopeState::Unmeasured;
            }
        }

        return new self($this->universe, $finalPaths, $state, $uncovered);
    }
}
