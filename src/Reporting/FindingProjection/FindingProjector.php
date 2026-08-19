<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\NamespaceExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Filter\PathExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Filter\PredicateFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;

/**
 * @qmx-ignore coupling.instability.class -- Finding projection intentionally composes the six ordered policy operations across Finding, Inline, Baseline, and Git contracts; its two callers and fifteen outgoing types are the reviewed Reporting orchestration boundary.
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
     * @param list<Violation> $violations
     * @param array<string, list<Suppression>> $suppressions
     */
    public function project(array $violations, array $suppressions, FindingProjectionOptions $options): FindingProjectionResult
    {
        $unfilterable = $this->configurationErrors($violations);
        $violations = $this->filterableFindings($violations);

        $annotation = $this->annotationSuppression->apply($violations, $suppressions);
        $violations = $annotation->retained;
        $restored = $annotation->suppressed;
        $removed = [ViolationFilterStage::Suppression->value => $options->annotationSuppressionDisabled ? [] : $restored];

        foreach ($this->exclusionStages($options) as $stage) {
            $outcome = $stage->apply($violations);
            $violations = $outcome->violations;
            $removed[$stage->stage()->value] = $outcome->removed;
            if ($restored !== []) {
                $restoredOutcome = $stage->apply(array_values($restored));
                $restored = $restoredOutcome->violations;
                $removed[$stage->stage()->value] = array_values([
                    ...$removed[$stage->stage()->value],
                    ...$restoredOutcome->removed,
                ]);
            }
        }

        $measured = $violations;
        $stale = [];
        $inert = [];
        $baselineScope = null;
        if ($options->baselinePath !== null && $options->baselinePath !== '') {
            $stage = new BaselineCeilingStage($this->baselineLoader->load($options->baselinePath), $this->declarations);
            $ceiling = $stage->judgeAll($violations);
            $violations = $ceiling->result->violations;
            $removed[ViolationFilterStage::Baseline->value] = $ceiling->result->removed;
            $stale = $ceiling->staleEntries;
            $inert = $ceiling->inertEntries;
            $baselineScope = $stage->baselineScope();
        }

        if ($options->annotationSuppressionDisabled) {
            $violations = array_values([...$violations, ...$restored]);
        }

        if ($options->gitScope !== null) {
            $git = $this->gitScopeQuery->resolve($options->gitScope);
            $pathSet = array_fill_keys($git->paths, true);
            $namespaceSet = array_fill_keys($git->namespaces, true);
            $filter = new class ($pathSet, $namespaceSet) implements ViolationFilterInterface {
                /**
                 * @param array<string, true> $paths
                 * @param array<string, true> $namespaces
                 */
                public function __construct(private array $paths, private array $namespaces) {}
                public function shouldInclude(Violation $violation): bool
                {
                    return isset($this->paths[$violation->location->pathString()])
                        || ($violation->symbolPath->namespace !== null && isset($this->namespaces[$violation->symbolPath->namespace]));
                }
            };
            $outcome = (new PredicateFilterStage(ViolationFilterStage::GitScope, $filter))->apply(array_values($violations));
            $violations = $outcome->violations;
            $removed[ViolationFilterStage::GitScope->value] = $outcome->removed;
        }

        return new FindingProjectionResult(
            array_values([...$violations, ...$unfilterable]),
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
     * A channel declaring
     * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability::ConfigurationError}
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
     * set at the end, where {@see FindingProjectionResult::$violations} is the
     * list `check` gates on.
     *
     * They are deliberately absent from the measured set: a baseline can
     * never accept one, so recording one would only ever produce an inert
     * entry.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function configurationErrors(array $violations): array
    {
        return array_values(array_filter($violations, $this->isConfigurationError(...)));
    }

    /**
     * The complement of {@see configurationErrors()} — everything the stages
     * below are allowed to act on.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function filterableFindings(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            fn(Violation $violation): bool => !$this->isConfigurationError($violation),
        ));
    }

    private function isConfigurationError(Violation $violation): bool
    {
        return $this->declarations->declarationFor($violation->channel())?->isConfigurationError() === true;
    }

    /** @return list<PredicateFilterStage> */
    private function exclusionStages(FindingProjectionOptions $options): array
    {
        $fileScope = DeclaredChannelFileScope::create();
        $stages = [];
        if ($options->excludePaths !== []) {
            $stages[] = new PredicateFilterStage(
                ViolationFilterStage::PathExclusion,
                new PathExclusionFilter(new PathMatcher(array_values($options->excludePaths)), $fileScope),
            );
        }
        $matcher = new NamespaceMatcher(array_values($options->excludeNamespaces));
        if (!$matcher->isEmpty()) {
            $stages[] = new PredicateFilterStage(
                ViolationFilterStage::NamespaceExclusion,
                new NamespaceExclusionFilter($matcher, $fileScope),
            );
        }
        return $stages;
    }
}
