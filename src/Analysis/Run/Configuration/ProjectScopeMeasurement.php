<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

/**
 * One measurement of a run against the project's production autoload roots,
 * carrying both answers {@see ProjectScopeCoverage} publishes.
 *
 * The two exist together because they are not the same question and empty is
 * not a synonym for covered. `uncoveredRoots` names what a scope warning
 * should print; `covers()` says whether a channel conditioned on scope may
 * speak. They agree on a project whose production autoload was read, and they
 * deliberately disagree on one whose autoload could not be read at all: no
 * root to name, and no licence to judge.
 */
final readonly class ProjectScopeMeasurement
{
    /**
     * @param list<string> $uncoveredRoots production autoload roots no analysed path contains
     * @param bool $readable whether a denominator to measure against existed
     */
    private function __construct(
        public array $uncoveredRoots,
        private bool $readable,
    ) {}

    /** @param list<string> $uncoveredRoots */
    public static function against(array $uncoveredRoots): self
    {
        return new self($uncoveredRoots, readable: true);
    }

    /** No manifest to narrow against: the run answers for the whole project. */
    public static function covered(): self
    {
        return new self([], readable: true);
    }

    /**
     * A manifest whose production autoload this product cannot read —
     * `classmap`, `psr-0` or `files` and no `psr-4`.
     */
    public static function unreadable(): self
    {
        return new self([], readable: false);
    }

    public function covers(): bool
    {
        return $this->readable && $this->uncoveredRoots === [];
    }
}
