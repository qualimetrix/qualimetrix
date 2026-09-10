<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Core\Path\AbsolutePath;

/** Immutable execution input owned by Run. */
final readonly class RunConfiguration
{
    /**
     * @param list<AbsolutePath> $paths
     * @param list<string> $pathExcludes
     * @param bool $coversProjectScope Whether `$paths` cover the project's production autoload roots
     *
     * `$coversProjectScope` is the answer
     * {@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage}
     * gave for these exact paths, and it travels with them because it is a
     * fact about them. A rule or a filter that reports a configured value as
     * having bound to nothing must read it first: "bound nothing" is a fact
     * about the pair (configuration, run scope), and on a run narrowed to a
     * slice the same configuration binds perfectly well outside it.
     *
     * It has no default. Every site that narrows a run builds a new
     * configuration, and a defaulted "covers" would let one of them keep the
     * wider run's answer in silence — which is the acceptance this field
     * exists to prevent.
     */
    public function __construct(
        public array $paths,
        public array $pathExcludes,
        public AbsolutePath $projectRoot,
        public GeneratedFilePolicy $generatedFilePolicy,
        public bool $coversProjectScope,
    ) {}
}
