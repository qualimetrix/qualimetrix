<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

/**
 * One measurement of a run against the project's autoload targets,
 * carrying both answers {@see ProjectScopeCoverage} publishes.
 *
 * The two exist together because they are not the same question and empty is
 * not a synonym for covered. `uncoveredRoots` names what a scope warning
 * should print; `covers()` says whether a channel conditioned on scope may
 * speak. They agree on a project whose autoload was read, and they
 * deliberately disagree on one whose autoload could not be read at all: no
 * target to name, and no licence to judge.
 *
 * `prunedTargets` is a third answer and belongs to neither: the declared
 * targets that lie under a directory discovery never enters, which are
 * neither analysed by default nor counted in the denominator. It is carried
 * by both shapes, because a manifest whose every target is pruned is the
 * unreadable one, and what it dropped is still worth naming.
 */
final readonly class ProjectScopeMeasurement
{
    /**
     * @param list<string> $uncoveredRoots production autoload targets no analysed path contains
     * @param bool $readable whether a denominator to measure against existed
     * @param list<array{target: string, directory: string}> $prunedTargets declared targets under a pruned directory, and that directory
     */
    private function __construct(
        public array $uncoveredRoots,
        private bool $readable,
        public array $prunedTargets,
    ) {}

    /**
     * @param list<string> $uncoveredRoots
     * @param list<array{target: string, directory: string}> $prunedTargets
     */
    public static function against(array $uncoveredRoots, array $prunedTargets = []): self
    {
        return new self($uncoveredRoots, readable: true, prunedTargets: $prunedTargets);
    }

    /**
     * A manifest that declares no production autoload this product can read
     * at all: absent, unparseable, without an `autoload` section, or with
     * every production section in it empty or malformed.
     *
     * A `classmap`, `psr-0` or `files` section does not make the manifest
     * unreadable; those are ordinary path targets. See
     * {@see ProjectScopeCoverage} for the owning measurement.
     *
     * @param list<array{target: string, directory: string}> $prunedTargets
     */
    public static function unreadable(array $prunedTargets = []): self
    {
        return new self([], readable: false, prunedTargets: $prunedTargets);
    }

    public function covers(): bool
    {
        return $this->readable && $this->uncoveredRoots === [];
    }
}
