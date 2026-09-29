<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Core\Path\AbsolutePath;

/** Current selection and verdict over one captured source universe. */
final readonly class ProjectScopeMeasurement
{
    /**
     * @param list<AbsolutePath> $paths
     * @param list<string> $uncoveredRoots
     */
    public function __construct(
        public ProjectScopeUniverse $universe,
        public array $paths,
        private ProjectScopeState $scopeState,
        public array $uncoveredRoots,
    ) {}

    public function state(): ProjectScopeState
    {
        return $this->scopeState;
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
