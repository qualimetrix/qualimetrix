<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

use LogicException;

/** Measured scope questions derived from one set of withholding doors. */
final readonly class ProjectScopeJudgement
{
    /**
     * @param list<ProjectScopeDoor> $namespaceWithheldBy
     * @param list<ProjectScopeDoor> $selectorsWithheldBy
     * @param list<ExcludeSelectorVerdict> $excludeSelectors
     * @param RemovedCodeNamespaces|null $removedCode null means the removed code's namespaces were not
     *                                                established, which counts as unknown: a caller that
     *                                                knows nothing was removed passes `none()`
     * @param list<string> $installedConfigurationSources the `describe()` words of each configuration
     *                                                    source established as installed by Composer
     */
    public function __construct(
        private array $namespaceWithheldBy = [],
        private array $selectorsWithheldBy = [],
        private array $excludeSelectors = [],
        private ?RemovedCodeNamespaces $removedCode = null,
        private array $installedConfigurationSources = [],
    ) {
        self::assertDistinctDoors($namespaceWithheldBy);
        self::assertDistinctDoors($selectorsWithheldBy);
        self::assertSelectorDoors($selectorsWithheldBy);
        foreach ($excludeSelectors as $selector) {
            self::assertSelector($selector, $selectorsWithheldBy);
        }
    }

    /** @param list<ProjectScopeDoor> $doors */
    private static function assertDistinctDoors(array $doors): void
    {
        if (\count($doors) !== \count(array_unique(array_map(static fn(ProjectScopeDoor $door): string => $door->value, $doors)))) {
            throw new LogicException('Project scope doors must be distinct');
        }
    }

    /** @param list<ProjectScopeDoor> $doors */
    private static function assertSelectorDoors(array $doors): void
    {
        foreach ($doors as $door) {
            if ($door !== ProjectScopeDoor::Paths && $door !== ProjectScopeDoor::UnknownUniverse) {
                throw new LogicException('Exclude selector judgement accepts only path and universe doors');
            }
        }
    }

    /** @param list<ProjectScopeDoor> $selectorsWithheldBy */
    private static function assertSelector(ExcludeSelectorVerdict $selector, array $selectorsWithheldBy): void
    {
        $sourceKeys = array_map(serialize(...), $selector->sources);
        if (\count($sourceKeys) !== \count(array_unique($sourceKeys))) {
            throw new LogicException('Selector sources must be distinct');
        }
        self::assertNotJudgedSelector($selector, $selectorsWithheldBy);
        if ($selectorsWithheldBy !== [] && \in_array($selector->outcome, [ExcludeSelectorOutcome::Unmatched, ExcludeSelectorOutcome::CoveredBySameSource, ExcludeSelectorOutcome::CoveredByOtherSource], true)) {
            throw new LogicException('A closed selector question cannot conclude an unsettled selector is unmatched');
        }
        if ($selector->phpEvidence !== null && $selector->outcome !== ExcludeSelectorOutcome::Removed) {
            throw new LogicException('PHP evidence belongs to a removed run entry');
        }
    }

    /** @param list<ProjectScopeDoor> $selectorsWithheldBy */
    private static function assertNotJudgedSelector(ExcludeSelectorVerdict $selector, array $selectorsWithheldBy): void
    {
        if ($selector->outcome === ExcludeSelectorOutcome::NotJudged && $selectorsWithheldBy === []) {
            throw new LogicException('An open selector question cannot contain NotJudged');
        }
        if ($selector->outcome === ExcludeSelectorOutcome::NotJudged && $selector->removedEntries !== []) {
            throw new LogicException('A bound selector remains Removed on a partial run');
        }
    }

    public function judgesSelectedUniverse(): bool
    {
        foreach ($this->namespaceWithheldBy as $door) {
            if ($door === ProjectScopeDoor::Paths || $door === ProjectScopeDoor::UnknownUniverse) {
                return false;
            }
        }

        return true;
    }

    public function judgesNamespaceClaims(): bool
    {
        return $this->namespaceWithheldBy === [];
    }

    /** Whether an exact namespace selector is judged: no removed code may declare it. */
    public function judgesExactNamespace(string $namespace): bool
    {
        return $this->judgesBesideRemovedCode() && !$this->removedCode()->mayDeclare($namespace);
    }

    /** Whether a namespace subtree selector is judged: no removed code may declare within it. */
    public function judgesNamespaceSubtree(string $namespace): bool
    {
        return $this->judgesBesideRemovedCode() && !$this->removedCode()->mayDeclareWithin($namespace);
    }

    /**
     * Whether literal namespace selectors are decided by what removed code
     * declares alone, so that one is judged when that code misses it.
     *
     * Only authored removal of PHP files and generated removal can be set
     * aside. Any other door or an unlistable removed directory withholds every
     * literal selector, as {@see judgesNamespaceClaims()} does. Unknown removed
     * namespaces still withhold each selector through {@see RemovedCodeNamespaces}.
     */
    public function judgesBesideRemovedCode(): bool
    {
        if ($this->namespaceWithheldBy === []) {
            return true;
        }
        foreach ($this->namespaceWithheldBy as $door) {
            if ($door !== ProjectScopeDoor::Exclude && $door !== ProjectScopeDoor::Generated) {
                return false;
            }
        }

        return !\in_array(ProjectScopeDoor::Exclude, $this->namespaceWithheldBy, true) || $this->excludeEvidenceIsPhpFilesOnly();
    }

    /** Whether what removed code declares was established in full; when not, it may declare anything. */
    public function removedCodeIsKnown(): bool
    {
        return $this->removedCode()->isKnown();
    }

    /**
     * Removed code is consulted even when no door is open: code removed
     * outside the measured universe opens none, yet still leaves the
     * namespace tree.
     */
    private function removedCode(): RemovedCodeNamespaces
    {
        return $this->removedCode ?? RemovedCodeNamespaces::unknown();
    }

    private function excludeEvidenceIsPhpFilesOnly(): bool
    {
        $phpFile = false;
        foreach ($this->excludeSelectors as $selector) {
            if ($selector->phpEvidence === null) {
                continue;
            }
            if ($selector->phpEvidence !== 'php-file') {
                return false;
            }
            $phpFile = true;
        }

        return $phpFile;
    }

    /**
     * Whether the project answers for a value these sources wrote: false only
     * when every one is established as installed, and so can be neither edited
     * nor removed by the project. Nothing established fails closed.
     */
    public function judgesValueWrittenBy(ConfiguredValueWriters $writers): bool
    {
        foreach ($writers->origins as $origin) {
            if (!\in_array($origin->describe(), $this->installedConfigurationSources, true)) {
                return true;
            }
        }

        return false;
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
