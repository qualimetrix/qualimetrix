<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Core\Profiler\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/** Coordinates rule-producing preparation while capabilities retain their own state. */
final readonly class RuleProducerPreparation
{
    public function __construct(
        private LayerPolicyPreparationInterface $layerPolicyPreparation,
        private CircularDependencyPreparationInterface $circularDependencyPreparation,
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
     * @param list<SplFileInfo> $eligibleFiles
     */
    public function inspectFiles(array $eligibleFiles): void
    {
        $selection = $this->ruleConfiguration->selection();
        $this->fileSetInspection->inspect($eligibleFiles, $selection->only, $selection->disabled);
    }
}
