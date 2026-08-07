<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\Filter\PredicateFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageInterface;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Git\GitScopeFilter;

/**
 * Runs a run's findings through the ordered stages of
 * {@see ViolationFilterStage}: `@qmx-ignore` → `exclude_paths` →
 * `exclude_namespaces` → baseline → git scope.
 *
 * **The order is a behavioural contract** (§5.2 of the baseline-ceiling
 * plan), which is why {@see stages()} is public: an assertion about it reads
 * the list rather than inferring it from counters, which cannot tell "the
 * baseline ran fourth" from "the baseline removed nothing".
 *
 * Two positions in that order carry a decision:
 *
 * - **The baseline runs after suppression and exclusion.** Suppression is
 *   per line while a baseline identity spans a file or a class, so a
 *   baseline placed first would see *n* findings where everything
 *   downstream — capture included — sees *n−1*. The entry would read as
 *   breached on the very next run and promote its whole group to Error.
 *   One position means one set. The visible consequence is that a
 *   hand-written `@qmx-ignore` outranks a generated entry, which is the
 *   right way round.
 * - **Git scope runs last**, so narrowing the report cannot change what was
 *   accepted, captured or called stale (§2.4).
 *
 * **`--no-suppression-annotations` is honoured here rather than in the stage
 * list**, and that is the whole of its implementation. The measured set is
 * defined by configuration and annotations alone (§5.5), so the suppression
 * stage always runs; the flag only asks for the findings it removed to be put
 * back into the report, which happens after the baseline stage has judged the
 * set and before git scope narrows what is shown. Making the flag drop the
 * stage instead — the shape this class shipped with — let a run measure
 * findings no capture had recorded, and promoted them to Error on unchanged
 * code. See {@see CliOnlyNarrowing} for the invariant and its cost.
 */
final readonly class ViolationFilterPipeline
{
    public function __construct(
        private BaselineLoader $baselineLoader,
        private ChannelDeclarationRegistryInterface $declarations,
        private MeasuredViolationSet $measuredSet,
    ) {}

    /**
     * Loads per-file suppression tags into the suppression filter.
     *
     * Must be called before filter() for `@qmx-ignore` tags to take effect.
     *
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags
     */
    public function loadSuppressions(array $suppressions): void
    {
        $this->measuredSet->loadSuppressions($suppressions);
    }

    /**
     * The stages this run will apply, in order.
     *
     * The first three come from {@see MeasuredViolationSet}, which is the
     * single definition of the measured set; the baseline and git-scope
     * stages are appended here because neither belongs to that set.
     *
     * @return list<ViolationFilterStageInterface>
     */
    public function stages(ViolationFilterOptions $options): array
    {
        $stages = $this->measuredSet->stages($options->narrowing);

        if ($options->baselinePath !== null && $options->baselinePath !== '') {
            $stages[] = new BaselineCeilingStage(
                $this->baselineLoader->load($options->baselinePath),
                $this->declarations,
            );
        }

        if ($options->gitScope !== null) {
            $stages[] = new PredicateFilterStage(
                ViolationFilterStage::GitScope,
                new GitScopeFilter(
                    $options->gitScope->gitClient,
                    $options->gitScope->reportScope,
                    $options->gitScope->projectRoot,
                    !$options->gitScope->strictMode,
                ),
            );
        }

        return $stages;
    }

    /**
     * Applies every stage in order and reports what each of them did.
     *
     * Findings the suppression stage removed are carried alongside the run
     * when `--no-suppression-annotations` asks for them, on a path of their
     * own: they still meet every exclusion and the git scope — otherwise the
     * flag would start showing what an exclusion took out — but they never
     * reach the baseline stage, because they are not in the set it measures.
     * They rejoin the report immediately before git scope, or at the end of a
     * run that has no git scope, and are appended after the findings that
     * were never suppressed.
     *
     * @param list<Violation> $violations
     */
    public function filter(array $violations, ViolationFilterOptions $options): ViolationFilterResult
    {
        /** @var ?list<Violation> $measured */
        $measured = null;
        $removed = [];
        /** @var list<BaselineEntry> $stale */
        $stale = [];
        /** @var list<InertBaselineEntry> $inert */
        $inert = [];
        /** @var ?list<string> $baselineScope */
        $baselineScope = null;
        /** @var list<Violation> $restored */
        $restored = [];

        foreach ($this->stages($options) as $stage) {
            $current = $stage->stage();

            if ($measured === null && !$current->definesMeasuredSet()) {
                $measured = $violations;
            }

            if ($current === ViolationFilterStage::GitScope) {
                $violations = [...$violations, ...$restored];
                $restored = [];
            }

            if ($stage instanceof BaselineCeilingStage) {
                // Staleness and inert entries are read off the stage's
                // *input*: an entry is stale when its identity is absent
                // from what the ceiling measured, and the ceiling's output
                // has the accepted groups already taken out of it — reading
                // that would report every accepted entry as stale. One call
                // answers both, and the filtered result, together, so
                // nothing here can compute them from two different lists.
                $ceiling = $stage->judgeAll($violations);
                $outcome = $ceiling->result;
                $stale = $ceiling->staleEntries;
                $inert = $ceiling->inertEntries;
                $baselineScope = $stage->baselineScope();
            } else {
                $outcome = $stage->apply($violations);
            }

            $violations = $outcome->violations;
            $removed[$current->value] = $outcome->removed;

            if ($current === ViolationFilterStage::Suppression && $options->narrowing->annotationSuppressionDisabled) {
                // Suppressed, therefore outside the measured set — and then
                // handed back to the report, so nothing was removed from the
                // run and `--show-suppressed` must not claim otherwise.
                $restored = $outcome->removed;
                $removed[$current->value] = [];

                continue;
            }

            if ($restored !== [] && $current->definesMeasuredSet()) {
                $restoredOutcome = $stage->apply($restored);
                $restored = $restoredOutcome->violations;
                $removed[$current->value] = [...$removed[$current->value], ...$restoredOutcome->removed];
            }
        }

        return new ViolationFilterResult(
            violations: [...$violations, ...$restored],
            measuredViolations: $measured ?? $violations,
            removedByStage: $removed,
            staleEntries: $stale,
            inertEntries: $inert,
            baselineScope: $baselineScope,
        );
    }
}
