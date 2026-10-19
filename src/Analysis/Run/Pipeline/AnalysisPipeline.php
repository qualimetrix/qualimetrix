<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use Psr\Log\LoggerInterface;

use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Finding\Contract\Finding;

use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Analysis\Run\Contract\Pipeline\MeasuredRunResult;
use Qualimetrix\Analysis\Run\InlineDirectiveRun;
use Qualimetrix\Analysis\Run\Preparation\PreparedRun;

/**
 * Main analysis pipeline orchestrator.
 *
 * Coordinates all phases of static runtime:
 * 1. Discovery - Find PHP files to analyze
 * 2. Collection - Parse files and collect metrics + dependencies (single AST traversal)
 * 3. Build dependency graph from collected dependencies
 * 4. Measurement aggregation and global reaggregation
 * 5. Computed metric evaluation
 * 6. Circular dependency preparation
 * 7. File-set inspection
 * 8. Rule execution
 */
final class AnalysisPipeline implements AnalysisPipelineInterface, DirectiveAuditInterface
{
    public function __construct(
        private readonly \Qualimetrix\Analysis\Run\Preparation\RunPreparation $preparation,
        private readonly RuleExecutionInterface $ruleExecutor,
        private readonly RulePublicationInterface $rulePublication,
        private readonly InlineDirectiveRun $inlineDirectiveRun,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function analyze(RunConfiguration $configuration): AnalysisResult
    {
        $startTime = hrtime(true);
        [$prepared, $measuredScope] = $this->preparation->prepare($configuration);
        $late = $this->latePublishedResult($prepared);
        $latePublished = $late['findings'];
        $duration = (hrtime(true) - $startTime) / 1e9;

        $this->logger->info('Analysis complete', [
            'total_duration' => \sprintf('%.2fs', $duration),
            'violations' => \count($prepared->ruleExecution->published) + \count($latePublished),
            'files_analyzed' => $prepared->coverage->analyzedFilesCount(),
            'files_skipped' => $prepared->coverage->skippedFilesCount(),
        ]);

        return AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $prepared->context->metrics,
                coverage: $prepared->coverage,
                namespaceTree: $prepared->namespaceTree,
                projectScope: $measuredScope,
                duration: $duration,
                subjectCoverage: $prepared->subjectCoverage,
            ),
            directives: new DirectiveObservations(
                suppressions: $prepared->suppressions,
                thresholdOverrides: $prepared->thresholdOverrides,
            ),
            ruleExecution: $prepared->ruleExecution,
            latePublished: $latePublished,
            computedMetricEvaluation: $prepared->computedMetricEvaluation,
            population: $prepared->discoveryPopulation->merge($late['population']),
            populationPublication: $prepared->populationPublication,
        );
    }

    /**
     * The audit half of {@see DirectiveAuditInterface}, which states what a
     * verdict is relative to and what it does not measure.
     *
     * Everything below is the wiring: one prepared run, both halves of the
     * question asked against it, and one list in the order an author reads a
     * tree.
     */
    public function auditDirectives(
        RunConfiguration $configuration,
        DirectiveSweepScope $sweep = DirectiveSweepScope::Narrow,
    ): DirectiveAuditReport {
        [$prepared, $measuredScope] = $this->preparation->prepare($configuration);

        // What the rules produced, and nothing assembled after them. The
        // channel a run assembles late — `annotation.unused-directive` — used
        // to be spliced in here, because a suppression aimed at it came out
        // inert while `check` showed it silencing findings. No suppression can
        // aim at it now, so the splice can no longer move a verdict; what it
        // still moved was `producedFindings`, which would then have counted a
        // channel no verdict was judged against.
        $produced = $prepared->ruleExecution->produced;

        $verdicts = $this->inlineDirectiveRun->verdicts(
            $produced,
            $prepared->ruleExecution->levelActivity,
            $prepared->subjectCoverage,
            $prepared->context,
            $this->ruleExecutor,
            $prepared->ruleExecution,
            $sweep,
        );

        return new DirectiveAuditReport(
            verdicts: $verdicts,
            coverage: $prepared->coverage,
            producedFindings: \count($produced),
            sweep: $sweep,
            projectScope: $measuredScope,
        );
    }

    /**
     * What {@see AnalysisResult::$findings} carries: `$ruleExecution`'s published
     * findings plus the directive-usage audit below, which is not part of rule
     * execution proper and therefore not part of `$ruleExecution` itself.
     *
     * The directive-usage half of the inline-directive report can only be
     * answered once every rule has produced its findings — a suppression is
     * stale exactly when nothing it covers was reported. The channel identity
     * and the wording stay with the owning capability; Run only decides when
     * to ask.
     *
     * **The audit is asked about `produced`, not `published`, and the two
     * differ by exactly the wrong thing.** `published` has already lost the
     * per-rule `suppress_namespaces` / `suppress_namespace_channels` /
     * `suppress_paths` ledger and the per-finding channel selection — decisions
     * about what a *report* shows. Judging an annotation by them means a
     * suppression covering a finding the ledger would have dropped anyway is
     * reported as silencing nothing: a statement about configuration dressed
     * up as a statement about the author's annotation.
     *
     * The direction is one-way by construction: `SuppressionFilter::suppressesAny()`
     * is an existential over the finding list, so widening the list can only
     * turn "matched nothing" into "matched something" — the audit can lose a
     * stale report here, never gain one.
     *
     * **What is asked and what is reported are still two questions.** The
     * verdict is reached over `produced`; whether the verdict is *published*
     * obeys the same channel selection as every other finding, asked through
     * {@see RulePublicationInterface::publishable()}. Being assembled after
     * `execute()` used to mean being assembled past that filter, which made
     * `--disable-rule annotation.unused-directive` inert and let an
     * `--only-rule` naming a sibling channel publish this one.
     *
     * @return array{findings: list<Finding>, population: JudgedPopulation}
     */
    private function latePublishedResult(PreparedRun $prepared): array
    {
        $ruleExecution = $prepared->ruleExecution;
        $inline = $this->inlineDirectiveRun->usageResult(
            $ruleExecution->produced,
            $ruleExecution->levelActivity,
            $prepared->subjectCoverage,
            $prepared->populationPublication,
        );
        return [
            'findings' => $this->rulePublication->publishable([...$inline['findings'], ...$prepared->unmatchedExcludeFindings]),
            'population' => $inline['population'],
        ];
    }

}
