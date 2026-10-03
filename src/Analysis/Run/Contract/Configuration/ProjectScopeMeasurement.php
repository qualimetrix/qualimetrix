<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
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
        $state = $this->measuredState($pathsMissing, $unknownDenominator);
        $selectorDoors = self::selectorDoorsFor($pathsMissing, $unknownDenominator, $state);
        $namespaceDoors = $selectorDoors;
        if ($facts->generatedExcluded > 0) {
            $namespaceDoors[] = ProjectScopeDoor::Generated;
        }
        [$selectors, $selectorReasons, $excludeDoors] = self::selectorMeasurements($files, $selectorDoors);
        $namespaceDoors = [...$namespaceDoors, ...$excludeDoors];
        $reasons = [...$selectorReasons, ...$this->selectionReasons($files, $pathsMissing, $unknownDenominator)];

        return new self(
            $this->universe,
            $this->paths,
            $state,
            $pathsMissing ? array_map(static fn($path): string => $path->value(), $facts->missingByPaths) : [],
            new ProjectScopeJudgement(self::uniqueDoors($namespaceDoors), self::uniqueDoors($selectorDoors), $selectors),
            $reasons,
        );
    }

    private function measuredState(bool $hasMissingPaths, bool $hasUnknownDenominator): ProjectScopeState
    {
        $sourceIncomplete = \in_array($this->scopeState, [ProjectScopeState::Unknown, ProjectScopeState::Unmeasured], true);

        return match (true) {
            $hasUnknownDenominator => ProjectScopeState::Unknown,
            $hasMissingPaths => ProjectScopeState::Narrowed,
            $sourceIncomplete && !$this->universe->containsProjectRoot($this->paths) => ProjectScopeState::Unmeasured,
            $sourceIncomplete => ProjectScopeState::Unknown,
            default => ProjectScopeState::Covered,
        };

    }

    /** @return list<ProjectScopeDoor> */
    private static function selectorDoorsFor(bool $hasMissingPaths, bool $hasUnknownDenominator, ProjectScopeState $state): array
    {
        $selectorDoors = [];
        if ($hasMissingPaths) {
            $selectorDoors[] = ProjectScopeDoor::Paths;
        }
        if ($hasUnknownDenominator || $state === ProjectScopeState::Unmeasured) {
            $selectorDoors[] = ProjectScopeDoor::UnknownUniverse;
        }

        return $selectorDoors;
    }

    /**
     * @param list<ProjectScopeDoor> $selectorDoors
     *
     * @return array{list<ExcludeSelectorVerdict>, list<ProjectScopeReason>, list<ProjectScopeDoor>}
     */
    private static function selectorMeasurements(DiscoveredProjectFiles $files, array $selectorDoors): array
    {
        $namespaceDoors = [];
        $reasons = [];
        $selectors = [];
        foreach ($files->selectorVerdicts as $selector) {
            if ($selector->phpEvidence !== null) {
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
            if ($selector->outcome === ExcludeSelectorOutcome::CoveredByOtherSource) {
                $reasons[] = self::otherSourceReason($selector);
            }
            $selectors[] = $selector;
        }
        return [$selectors, $reasons, $namespaceDoors];
    }

    private static function otherSourceReason(ExcludeSelectorVerdict $selector): ProjectScopeReason
    {
        $sources = array_map(static fn($source): string => $source->describe(), $selector->coveredBySources);
        return new ProjectScopeReason(ProjectScopeReasonKind::Exclude, [
            'selector' => $selector->display,
            'coveredBy' => $selector->coveredBy ?? throw new LogicException('Other-source verdict requires a hider'),
            'sources' => $sources,
            'rerun' => 'Rerun without the exclude from ' . implode(', ', $sources) . ' to judge this selector.',
        ]);
    }

    /** @return list<ProjectScopeReason> */
    private function selectionReasons(DiscoveredProjectFiles $files, bool $hasMissingPaths, bool $hasUnknownDenominator): array
    {
        $facts = $files->scopeFacts;
        $reasons = [];
        if ($facts->generatedExcluded > 0) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::Generated, ['removedFiles' => $facts->generatedExcluded]);
        }
        if ($facts->namedFilesOnly && $hasMissingPaths) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::ExplicitFiles, [
                'namedFiles' => \count($this->paths),
                'missingFiles' => \count($facts->missingByPaths),
            ]);
        }
        if ($hasUnknownDenominator) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::UnlistableOutsidePaths, [
                'unlistable' => array_map(static fn($path): string => $path->value(), $facts->unlistableOutside),
                'hidden' => array_map(static fn($path): string => $path->value(), $facts->hiddenOutsideDirectories),
            ]);
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::IncompleteUniverse, ['source' => 'project-tree']);
        }

        return $reasons;
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
