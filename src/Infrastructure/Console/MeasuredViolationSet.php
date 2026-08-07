<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Core\Violation\Filter\NamespaceExclusionFilter;
use Qualimetrix\Core\Violation\Filter\PathExclusionFilter;
use Qualimetrix\Core\Violation\Filter\PredicateFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageInterface;
use Qualimetrix\Core\Violation\Violation;

/**
 * The one definition of the set a baseline measures (§5.5 of the
 * baseline-ceiling plan): the run's findings after `@qmx-ignore` and the
 * exclusions, before any report narrowing.
 *
 * **Why it is a seam and not a step inside the pipeline.** Capture,
 * acceptance, staleness and resolved-reporting must all read the same set,
 * and they are not all reached from the same place: `check` arrives through
 * {@see ViolationFilterPipeline}, while a baseline command has no `check`
 * options to read and no pipeline to run. Before this class the set existed
 * only as a local variable half-way through one 115-line method, and the
 * same predicate was evaluated twice per run from two separately supplied
 * lists — they agreed only for as long as the baseline ran first.
 *
 * So the definition lives here in one place with two ways in:
 *
 * - {@see forPaths()} — paths in, the measured set out. This is what a
 *   command that only wants the set calls; it owns the analysis run.
 * - {@see stages()} — the ordered stages that produce it, for a caller that
 *   already holds the findings and continues past the set. That is `check`:
 *   the pipeline appends the baseline and git-scope stages to this list, so
 *   `check` and every baseline command are running the same three stages
 *   over the same configuration rather than two implementations that have
 *   to be kept in agreement.
 *
 * The set is defined by configuration and by the source's own annotations.
 * `check`'s own flags narrow the run further but do not redefine the set —
 * see {@see CliOnlyNarrowing} for why, and for what that costs.
 *
 * **Narrowing only, in one direction.** A flag may shrink the set, because a
 * group that lost members cannot breach the entry that bounded it; no flag may
 * grow it, because a finding the set never held has no entry and would read as
 * a breach on untouched code. That is why the exclusion flags are merged into
 * the exclusion stages here while `--no-suppression-annotations` is not read at
 * all: annotation suppression runs unconditionally, and the flag is honoured
 * downstream in {@see ViolationFilterPipeline}, past the point where the set is
 * taken.
 */
final readonly class MeasuredViolationSet
{
    public function __construct(
        private AnalysisPipelineInterface $analyzer,
        private SuppressionFilter $suppressionFilter,
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Analyses the given paths and returns the findings a baseline measures.
     *
     * No `InputInterface` is involved: the configuration is the one already
     * resolved into the provider, so a command that does not declare
     * `check`'s options can still obtain exactly the set `check` measures.
     *
     * @param list<AbsolutePath> $paths
     *
     * @return list<Violation>
     */
    public function forPaths(array $paths, ?FileDiscoveryInterface $fileDiscovery = null): array
    {
        $result = $this->analyzer->analyze($paths, $fileDiscovery);

        $this->loadSuppressions($result->suppressions);

        return $this->measure($result->violations);
    }

    /**
     * Hands the run's `@qmx-ignore` tags to the suppression filter.
     *
     * Must run before the suppression stage does; {@see forPaths()} does it
     * itself, and a caller that supplies its own findings does it through
     * the pipeline.
     *
     * @param array<string, list<Suppression>> $suppressions per-file suppression tags
     */
    public function loadSuppressions(array $suppressions): void
    {
        $this->suppressionFilter->clearSuppressions();

        foreach ($suppressions as $file => $fileSuppressions) {
            $this->suppressionFilter->setSuppressions($file, $fileSuppressions);
        }
    }

    /**
     * The stages that produce the measured set, in the order they run.
     *
     * An *exclusion* stage whose configuration is empty is left out rather
     * than run as a no-op, so the enumerated list names the filtering a run
     * actually performs. Suppression is the exception and is always present:
     * whether the run has any `@qmx-ignore` tags is not known here — they are
     * loaded per run into the filter, not read from configuration — and
     * making the stage conditional is how a flag came to widen the set. Its
     * presence is also what keeps the list non-empty, so the set is never the
     * raw analysis output by default.
     *
     * @return list<ViolationFilterStageInterface>
     */
    public function stages(CliOnlyNarrowing $narrowing = new CliOnlyNarrowing()): array
    {
        $configuration = $this->configurationProvider->getConfiguration();

        $stages = [new PredicateFilterStage(ViolationFilterStage::Suppression, $this->suppressionFilter)];

        $paths = array_values(array_unique([
            ...$configuration->excludePaths,
            ...$narrowing->excludePaths,
        ]));

        if ($paths !== []) {
            $stages[] = new PredicateFilterStage(
                ViolationFilterStage::PathExclusion,
                new PathExclusionFilter(new PathMatcher($paths)),
            );
        }

        $namespaceMatcher = new NamespaceMatcher(array_values(array_unique([
            ...$configuration->excludeNamespaces,
            ...$narrowing->excludeNamespaces,
        ])));

        if (!$namespaceMatcher->isEmpty()) {
            $stages[] = new PredicateFilterStage(
                ViolationFilterStage::NamespaceExclusion,
                new NamespaceExclusionFilter($namespaceMatcher),
            );
        }

        return $stages;
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function measure(array $violations): array
    {
        foreach ($this->stages() as $stage) {
            $violations = $stage->apply($violations)->violations;
        }

        return $violations;
    }
}
