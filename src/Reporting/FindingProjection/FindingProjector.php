<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\PredicateFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;

/**
 * @qmx-ignore coupling.instability:class -- Finding projection intentionally composes the six ordered policy operations across Finding, Inline, Baseline, and Git contracts; its two callers and fifteen outgoing types are the reviewed Reporting orchestration boundary.
 */
final readonly class FindingProjector
{
    public function __construct(
        private AnnotationSuppressionInterface $annotationSuppression,
        BaselineLoader $baselineLoader,
        private ChannelDeclarationRegistryInterface $declarations,
        private GitScopeQueryInterface $gitScopeQuery,
        UnusedEntryAudit $unusedEntryAudit,
    ) {
        $this->baselineProjection = new BaselineFindingProjection($baselineLoader, $declarations, $unusedEntryAudit);
    }

    private BaselineFindingProjection $baselineProjection;

    public function preflightBaseline(string $path): BaselineDocument
    {
        return BaselineLoader::preflight($path);
    }

    /**
     * Git keeps declared project-scoped channels, changed file locations,
     * and non-strict namespace or unlocated project aggregates. A project
     * subject with a file location (such as a duplication copy) is judged by
     * that copy's own file.
     *
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

        foreach (ConfiguredExclusionProjection::stages($options) as $stage) {
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
        $ceiling = null;
        $unusedAuditPublished = true;
        if ($options->baselineDocument !== null) {
            $baseline = $this->baselineProjection->project($findings, $options);
            $ceiling = $baseline['ceiling'];
            $findings = $ceiling->result->findings;
            $removed[FindingFilterStage::Baseline->value] = $ceiling->result->removed;
            $stale = $ceiling->staleEntries;
            $inert = $ceiling->inertEntries;
            $baselineScope = $baseline['scope'];
            $unusedAuditPublished = $baseline['auditPublished'];
            $findings = [...$findings, ...$baseline['audit']];
        }

        if ($options->annotationSuppressionDisabled) {
            $findings = array_values([...$findings, ...$restored]);
        }

        if ($options->gitScope !== null) {
            $git = $this->gitScopeQuery->resolve($options->gitScope);
            $pathSet = array_fill_keys($git->paths, true);
            $namespaceSet = array_fill_keys($git->namespaces, true);
            $fileScope = DeclaredChannelFileScope::create();
            $filter = new GitScopeFindingFilter(
                $pathSet,
                $namespaceSet,
                $options->gitScope->includeParentNamespaces,
                $fileScope,
            );
            $outcome = (new PredicateFilterStage(FindingFilterStage::GitScope, $filter))->apply(array_values($findings));
            $findings = $outcome->findings;
            $removed[FindingFilterStage::GitScope->value] = $outcome->removed;
        }

        return new FindingProjectionResult(
            findings: array_values([...$findings, ...$unfilterable]),
            annotationSuppression: $annotation,
            measuredFindings: array_values($measured),
            removedByStage: array_map(array_values(...), $removed),
            staleEntries: $stale,
            inertEntries: $inert,
            baselineScope: $baselineScope,
            ceilingOutcome: $ceiling,
            unusedAuditPublished: $unusedAuditPublished,
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
     * not `@qmx-ignore`, not `suppress_paths` or `suppress_namespaces`, not a
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
}
