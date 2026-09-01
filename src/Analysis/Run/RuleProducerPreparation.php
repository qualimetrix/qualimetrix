<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/** Coordinates rule-producing preparation while capabilities retain their own state. */
final readonly class RuleProducerPreparation
{
    public function __construct(
        private LayerPolicyPreparationInterface $layerPolicyPreparation,
        private CircularDependencyPreparationInterface $circularDependencyPreparation,
        private InlineDirectivePolicyInterface $inlineDirectivePolicy,
        private ThresholdDirectiveAuditInterface $thresholdDirectiveAudit,
        private FileSetInspectionComposite $fileSetInspection,
        private RuleSelector $ruleSelector,
        private RuleConfigurationInterface $ruleConfiguration,
    ) {}

    /**
     * @param iterable<SymbolPath> $classUniverse
     */
    public function prepareArchitecture(
        DependencyGraphInterface $graph,
        iterable $classUniverse,
        ProfilerInterface $profiler,
    ): void {
        $selection = $this->ruleConfiguration->selection();
        $enabled = false;

        // Every producer that reads the prepared policy, not just the first
        // one: `architecture.unassigned-class` used to be a channel of the
        // layer-violation rule and so was covered by asking about that rule
        // alone. As a producer of its own it is not, and asking about one of
        // two left `--only-rule=architecture.unassigned-class` reaching an
        // unprepared policy. The list is the capability's, not the run's.
        foreach (LayerPolicyPreparationInterface::PRODUCER_RULE_NAMES as $producerRuleName) {
            if ($this->ruleSelector->isProducerEnabled($producerRuleName, $selection->only, $selection->disabled)) {
                $enabled = true;

                break;
            }
        }

        if (!$enabled) {
            $this->layerPolicyPreparation->reset();

            return;
        }

        $profiler->start('architecture-prepare', 'pipeline');
        $this->layerPolicyPreparation->prepare($graph, $classUniverse);
        $profiler->stop('architecture-prepare');
    }

    public function prepareCircularDependencies(
        DependencyGraphInterface $graph,
        ProfilerInterface $profiler,
    ): void {
        $selection = $this->ruleConfiguration->selection();
        if (!$this->ruleSelector->isProducerEnabled(
            CircularDependencyPreparationInterface::PRODUCER_RULE_NAME,
            $selection->only,
            $selection->disabled,
        )) {
            $this->circularDependencyPreparation->reset();

            return;
        }

        $profiler->start('cycles', 'pipeline');
        $this->circularDependencyPreparation->prepare($graph);
        $profiler->stop('cycles');
    }

    /**
     * Hands this run's inline directives to the capability that owns them,
     * under the same enablement rule as every other producer here: a disabled
     * rule gets a cleared state rather than a prepared one.
     *
     * @param array<string, list<Suppression>> $suppressions
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides
     * @param array<string, list<ThresholdDiagnostic>> $thresholdDiagnostics
     */
    public function prepareInlineDirectives(
        array $suppressions,
        array $thresholdOverrides,
        array $thresholdDiagnostics,
    ): void {
        $selection = $this->ruleConfiguration->selection();
        if (!$this->ruleSelector->isProducerEnabled(
            InlineDirectivePolicyInterface::PRODUCER_RULE_NAME,
            $selection->only,
            $selection->disabled,
        )) {
            $this->inlineDirectivePolicy->reset();

            return;
        }

        $this->inlineDirectivePolicy->prepare($suppressions, $thresholdOverrides, $thresholdDiagnostics);
    }

    /**
     * The second question about the same directives, asked once the findings
     * exist: which of them silenced nothing.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function auditInlineDirectives(array $findings): array
    {
        return $this->inlineDirectivePolicy->auditDirectiveUsage($findings);
    }

    /**
     * What each authored suppression did, as verdicts rather than as the one
     * channel the run publishes.
     *
     * @param list<Finding> $findings
     *
     * @return list<DirectiveVerdict>
     */
    public function directiveVerdicts(array $findings): array
    {
        return $this->inlineDirectivePolicy->directiveVerdicts($findings);
    }

    /**
     * The other half of the same question, and the expensive one: what each
     * authored `@qmx-threshold` did.
     *
     * It is asked here rather than from the pipeline for the same reason
     * {@see auditInlineDirectives()} is: this is where Run holds its side of
     * the conversation with the capabilities that produce rules, so the
     * pipeline keeps naming phases instead of collaborators.
     *
     * @return list<DirectiveVerdict>
     */
    public function auditThresholdDirectives(
        AnalysisContext $context,
        RuleExecutionInterface $executor,
        RuleExecutionResult $baseline,
    ): array {
        return $this->thresholdDirectiveAudit->verdicts(
            new ThresholdDirectiveAuditInput($context, $executor, $baseline),
        );
    }

    /**
     * @param list<SplFileInfo> $eligibleFiles
     */    /**
     * @param list<SplFileInfo> $eligibleFiles
     */
    public function inspectFiles(array $eligibleFiles, AbsolutePath $projectRoot): void
    {
        $selection = $this->ruleConfiguration->selection();
        $this->fileSetInspection->inspect($eligibleFiles, $projectRoot, $selection->only, $selection->disabled);
    }
}
