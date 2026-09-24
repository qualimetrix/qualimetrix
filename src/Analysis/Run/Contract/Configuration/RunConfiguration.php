<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;

/** Immutable execution input owned by Run. */
final readonly class RunConfiguration
{
    /**
     * @param list<AbsolutePath> $paths
     * @param list<PathPattern> $pathExcludes built-in and authored directory selectors
     * @param bool $coversProjectScope Whether `$paths` cover every autoload target `$autoloadDevPolicy` counts as the project,
     *                                 or the manifest declares none and `$paths` are the project
     * @param list<PathPattern> $authoredPathExcludes The subset of `$pathExcludes` the user wrote
     * @param AutoloadDevPolicy $autoloadDevPolicy Whether `autoload-dev` code is part of the project
     *
     * `$coversProjectScope` is the answer
     * {@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage}
     * gave for these exact paths, and it travels with them because it is a
     * fact about them. A rule or a filter that reports a configured value as
     * having bound to nothing must read it first: "bound nothing" is a fact
     * about the pair (configuration, run scope), and on a run narrowed to a
     * slice the same configuration binds perfectly well outside it.
     *
     * `$authoredPathExcludes` exists because `$pathExcludes` cannot answer the
     * only question worth asking about it. That list is the author's entries
     * accumulated onto the built-in `vendor`, `node_modules` and `.git`, and
     * the three are indistinguishable once merged — yet `node_modules` is
     * legitimately absent from most PHP trees, so a channel judging the merged
     * list would report the product's own defaults as the author's mistake.
     *
     * Neither has a default. Every site that narrows a run builds a new
     * configuration, and a defaulted answer would let one of them keep the
     * wider run's in silence — which is the acceptance these fields exist to
     * prevent.
     *
     * `$autoloadDevPolicy` does have one, and the reasoning above does not
     * reach it: it is what the author said the project is, not an answer
     * about these paths, so narrowing a run never changes it. It travels
     * with the paths all the same, because a narrowed run is judged against
     * the same project as the run it was narrowed from.
     */
    public function __construct(
        public array $paths,
        public array $pathExcludes,
        public AbsolutePath $projectRoot,
        public GeneratedFilePolicy $generatedFilePolicy,
        public bool $coversProjectScope,
        public array $authoredPathExcludes,
        public AutoloadDevPolicy $autoloadDevPolicy = AutoloadDevPolicy::Exclude,
    ) {}

    /**
     * The same configuration over a re-resolved set of paths that still covers
     * the project: its autoload targets, or — with no manifest to declare
     * them — whatever the paths are.
     *
     * Two methods rather than one taking the answer as a flag, because the
     * answer is not a parameter of the same operation — it is which operation
     * this is. The paths and the coverage verdict travel together either way:
     * a transfer carrying only the paths would silently keep the wider run's
     * `$coversProjectScope`, which is the inheritance that field has no
     * default to prevent. The console rebuilt this object positionally before,
     * and every field added since had to be remembered at that one call site
     * or be lost there.
     *
     * @param list<AbsolutePath> $paths
     */
    public function coveringProjectScope(array $paths): self
    {
        return new self(
            paths: $paths,
            pathExcludes: $this->pathExcludes,
            projectRoot: $this->projectRoot,
            generatedFilePolicy: $this->generatedFilePolicy,
            coversProjectScope: true,
            authoredPathExcludes: $this->authoredPathExcludes,
            autoloadDevPolicy: $this->autoloadDevPolicy,
        );
    }

    /**
     * The same configuration over paths that no longer cover those targets.
     *
     * @param list<AbsolutePath> $paths
     */
    public function narrowedTo(array $paths): self
    {
        // Spelled out rather than delegated to one private helper taking the
        // verdict as a flag: a shared body would need that flag, and the flag
        // is what the two methods exist to remove.
        return new self(
            paths: $paths,
            pathExcludes: $this->pathExcludes,
            projectRoot: $this->projectRoot,
            generatedFilePolicy: $this->generatedFilePolicy,
            coversProjectScope: false,
            authoredPathExcludes: $this->authoredPathExcludes,
            autoloadDevPolicy: $this->autoloadDevPolicy,
        );
    }
}
