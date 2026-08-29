<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Closure;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\NamespaceExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Filter\PathExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Filter\PredicateFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;

/**
 * @qmx-ignore coupling.instability:class -- Finding projection intentionally composes the six ordered policy operations across Finding, Inline, Baseline, and Git contracts; its two callers and fifteen outgoing types are the reviewed Reporting orchestration boundary.
 */
final readonly class FindingProjector
{
    public function __construct(
        private AnnotationSuppressionInterface $annotationSuppression,
        private BaselineLoader $baselineLoader,
        private ChannelDeclarationRegistryInterface $declarations,
        private GitScopeQueryInterface $gitScopeQuery,
    ) {}

    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    public function project(array $findings, array $suppressions, FindingProjectionOptions $options): FindingProjectionResult
    {
        $unfilterable = $this->configurationErrors($findings);
        $findings = $this->filterableFindings($findings);

        $annotation = $this->annotationSuppression->apply($findings, $suppressions);
        $findings = $annotation->retained;
        $restored = $annotation->suppressed;
        $removed = [FindingFilterStage::Suppression->value => $options->annotationSuppressionDisabled ? [] : $restored];

        foreach ($this->exclusionStages($options) as $stage) {
            $outcome = $stage->apply($findings);
            $findings = $outcome->findings;
            $removed[$stage->stage()->value] = $outcome->removed;
            if ($restored !== []) {
                $restoredOutcome = $stage->apply(array_values($restored));
                $restored = $restoredOutcome->findings;
                $removed[$stage->stage()->value] = array_values([
                    ...$removed[$stage->stage()->value],
                    ...$restoredOutcome->removed,
                ]);
            }
        }

        $measured = $findings;
        $stale = [];
        $inert = [];
        $baselineScope = null;
        if ($options->baselinePath !== null && $options->baselinePath !== '') {
            $stage = new BaselineCeilingStage($this->baselineLoader->load($options->baselinePath), $this->declarations);
            $ceiling = $stage->judgeAll($findings);
            $findings = $ceiling->result->findings;
            $removed[FindingFilterStage::Baseline->value] = $ceiling->result->removed;
            $stale = $ceiling->staleEntries;
            $inert = $ceiling->inertEntries;
            $baselineScope = $stage->baselineScope();
        }

        if ($options->annotationSuppressionDisabled) {
            $findings = array_values([...$findings, ...$restored]);
        }

        if ($options->gitScope !== null) {
            $git = $this->gitScopeQuery->resolve($options->gitScope);
            $pathSet = array_fill_keys($git->paths, true);
            $namespaceSet = array_fill_keys($git->namespaces, true);
            // The same scope the exclusion stages read — see exclusionStages().
            $fileScope = DeclaredChannelFileScope::create();
            $filter = new class (
                $pathSet,
                $namespaceSet,
                static fn(Finding $finding): bool => !$fileScope->isFileScoped($finding->channel()),
            ) implements FindingFilterInterface {
                /**
                 * @param array<string, true> $paths
                 * @param array<string, true> $namespaces
                 * @param Closure(Finding): bool $isProjectScoped
                 */
                public function __construct(
                    private array $paths,
                    private array $namespaces,
                    private Closure $isProjectScoped,
                ) {}
                public function shouldInclude(Finding $finding): bool
                {
                    if (($this->isProjectScoped)($finding)) {
                        return true;
                    }

                    return isset($this->paths[$finding->location->pathString()])
                        || ($finding->symbolPath->namespace !== null && isset($this->namespaces[$finding->symbolPath->namespace]));
                }
            };
            $outcome = (new PredicateFilterStage(FindingFilterStage::GitScope, $filter))->apply(array_values($findings));
            $findings = $outcome->findings;
            $removed[FindingFilterStage::GitScope->value] = $outcome->removed;
        }

        return new FindingProjectionResult(
            array_values([...$findings, ...$unfilterable]),
            array_values($measured),
            array_map(array_values(...), $removed),
            $stale,
            $inert,
            $baselineScope,
        );
    }

    /**
     * The findings no stage of this projection is allowed to see.
     *
     * A channel declared by a
     * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}
     * reports that the tool cannot do what the configuration asked. That is
     * not a judgement about the code, so it is not something a user is
     * entitled to filter out — and the promise is that *nothing* filters it:
     * not `@qmx-ignore`, not `exclude_paths` or `exclude_namespaces`, not a
     * baseline, not a report narrowed to a git range.
     *
     * Holding these findings out of the pipeline is the mechanism, rather
     * than a guard inside each stage, for two reasons. A guard has to be
     * remembered by every stage added later, and only the baseline stage ever
     * remembered it. And a guard that merely keeps the exit code non-zero
     * still lets the finding vanish from the report — the user then sees a
     * red build with no stated cause. Withheld findings rejoin the reported
     * set at the end, where {@see FindingProjectionResult::$findings} is the
     * list `check` gates on.
     *
     * They are deliberately absent from the measured set: a baseline can
     * never accept one, so recording one would only ever produce an inert
     * entry.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function configurationErrors(array $findings): array
    {
        return array_values(array_filter($findings, $this->isConfigurationError(...)));
    }

    /**
     * The complement of {@see configurationErrors()} — everything the stages
     * below are allowed to act on.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function filterableFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn(Finding $finding): bool => !$this->isConfigurationError($finding),
        ));
    }

    private function isConfigurationError(Finding $finding): bool
    {
        return $this->declarations->declarationFor($finding->channel())?->isConfigurationError() === true;
    }

    /**
     * The exclusion stages, and with them the answer every narrowing stage of
     * this projection shares: **a stage that selects findings by the file
     * they sit in must not touch a finding that is not about a file.**
     *
     * Which channels those are is declared by the capabilities themselves and
     * assembled by {@see DeclaredChannelFileScope}, so the guarantee extends
     * to the next project-scoped channel without anyone editing this class.
     * That one scope is what all three narrowing stages read — `exclude_paths`
     * and `exclude_namespaces` here, and the git-range narrowing in
     * {@see project()}. Letting each stage decide for itself is what produced
     * two answers to one question: `architecture.unassigned-class` was exempt
     * from the first two and silently dropped by the third, so
     * `--report=git:staged` turned a gate the user had switched on into a
     * green build.
     *
     * Deliberately not a guard on "the finding carries no location": that
     * is an observation about one emission site, and reading scope out of it
     * would reintroduce exactly the derived-from-a-convention rule
     * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope}
     * exists to replace. A project-scoped channel that does print an example
     * location — `architecture.layer-violation` names the offending edge's
     * use site — is still a statement about the project, and is narrowed by
     * none of the three.
     *
     * @return list<PredicateFilterStage>
     */
    private function exclusionStages(FindingProjectionOptions $options): array
    {
        $fileScope = DeclaredChannelFileScope::create();
        $stages = [];
        if ($options->excludePaths !== []) {
            $stages[] = new PredicateFilterStage(
                FindingFilterStage::PathExclusion,
                new PathExclusionFilter(new PathMatcher(array_values($options->excludePaths)), $fileScope),
            );
        }
        $matcher = new NamespaceMatcher(array_values($options->excludeNamespaces));
        if (!$matcher->isEmpty()) {
            $stages[] = new PredicateFilterStage(
                FindingFilterStage::NamespaceExclusion,
                new NamespaceExclusionFilter($matcher, $fileScope),
            );
        }
        return $stages;
    }
}
