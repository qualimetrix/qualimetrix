<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
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
        if (!$this->ruleSelector->isProducerEnabled(
            LayerPolicyPreparationInterface::PRODUCER_RULE_NAME,
            $selection->only,
            $selection->disabled,
        )) {
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
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    public function auditInlineDirectives(array $violations): array
    {
        return $this->inlineDirectivePolicy->auditDirectiveUsage($violations);
    }

    /**
     * @param list<SplFileInfo> $eligibleFiles
     */
    public function inspectFiles(array $eligibleFiles, AbsolutePath $projectRoot): void
    {
        $selection = $this->ruleConfiguration->selection();
        $this->fileSetInspection->inspect($eligibleFiles, $projectRoot, $selection->only, $selection->disabled);
    }
}
