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
 * target to name, and no licence to judge.
 */
final readonly class ProjectScopeMeasurement
{
    /**
     * @param list<string> $uncoveredRoots production autoload targets no analysed path contains
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

    /**
     * A manifest that declares no production autoload this product can read
     * at all: absent, unparseable, without an `autoload` section, or with
     * every production section in it empty or malformed.
     *
     * Superseded (X16 F4): a `classmap`, `psr-0` or `files` section used to
     * reach this answer too. Those are ordinary path targets now — see
     * {@see ProjectScopeCoverage} for the measurement that reversed it.
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
