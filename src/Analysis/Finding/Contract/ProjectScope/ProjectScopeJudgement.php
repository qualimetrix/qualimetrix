<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

use LogicException;

/** Two measured questions: declaration absence and authored exclude selectors. */
final readonly class ProjectScopeJudgement
{
    /**
     * @param list<ProjectScopeDoor> $namespaceWithheldBy
     * @param list<ProjectScopeDoor> $selectorsWithheldBy
     * @param list<ExcludeSelectorVerdict> $excludeSelectors
     */
    public function __construct(
        private array $namespaceWithheldBy = [],
        private array $selectorsWithheldBy = [],
        private array $excludeSelectors = [],
    ) {
        if (\count($namespaceWithheldBy) !== \count(array_unique(array_map(static fn(ProjectScopeDoor $door): string => $door->value, $namespaceWithheldBy)))
            || \count($selectorsWithheldBy) !== \count(array_unique(array_map(static fn(ProjectScopeDoor $door): string => $door->value, $selectorsWithheldBy)))) {
            throw new LogicException('Project scope doors must be distinct');
        }
        foreach ($selectorsWithheldBy as $door) {
            if ($door !== ProjectScopeDoor::Paths && $door !== ProjectScopeDoor::UnknownUniverse) {
                throw new LogicException('Exclude selector judgement accepts only path and universe doors');
            }
        }
        foreach ($excludeSelectors as $selector) {
            $sourceKeys = array_map(serialize(...), $selector->sources);
            if (\count($sourceKeys) !== \count(array_unique($sourceKeys))) {
                throw new LogicException('Selector sources must be distinct');
            }
            if ($selector->outcome === ExcludeSelectorOutcome::NotJudged && $selectorsWithheldBy === []) {
                throw new LogicException('An open selector question cannot contain NotJudged');
            }
            if ($selector->outcome === ExcludeSelectorOutcome::NotJudged && $selector->removedEntries !== []) {
                throw new LogicException('A bound selector remains Removed on a partial run');
            }
            if ($selectorsWithheldBy !== [] && \in_array($selector->outcome, [ExcludeSelectorOutcome::Unmatched, ExcludeSelectorOutcome::CoveredBySameSource, ExcludeSelectorOutcome::CoveredByOtherSource], true)) {
                throw new LogicException('A closed selector question cannot conclude an unsettled selector is unmatched');
            }
            if ($selector->phpEvidence !== null && $selector->outcome !== ExcludeSelectorOutcome::Removed) {
                throw new LogicException('PHP evidence belongs to a removed run entry');
            }
        }
    }

    public function judgesNamespaceClaims(): bool
    {
        return $this->namespaceWithheldBy === [];
    }

    /** @return list<ProjectScopeDoor> */
    public function withheldBy(): array
    {
        return $this->namespaceWithheldBy;
    }

    public function judgesExcludeSelectors(): bool
    {
        return $this->selectorsWithheldBy === [];
    }

    /** @return list<ProjectScopeDoor> */
    public function selectorDoors(): array
    {
        return $this->selectorsWithheldBy;
    }

    /** @return list<ExcludeSelectorVerdict> */
    public function excludeSelectors(): array
    {
        return $this->excludeSelectors;
    }
}
