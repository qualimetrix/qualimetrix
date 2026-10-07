<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\UnmatchedTypeWarningInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/** Coordinates rule-producing preparation while capabilities retain their own state. */
final readonly class RuleProducerPreparation
{
    /**
     * @param RuleSelectorProducerGate $producerGate the one predicate every preparation here is
     *                                               gated on, so that a second way of switching a
     *                                               producer off has one place to be taught. Two
     *                                               preparations asking the selector directly while
     *                                               the file set asked the gate was three answers
     *                                               to one question, and the rule's own
     *                                               `enabled: false` reached none of them. It is
     *                                               the same instance the file set is given, so
     *                                               that one subject has one construction.
     */
    public function __construct(
        private LayerPolicyPreparationInterface&UnmatchedTypeWarningInterface $layerPolicyPreparation,
        private CircularDependencyPreparationInterface $circularDependencyPreparation,
        private FileSetInspectionComposite $fileSetInspection,
        private RuleSelectorProducerGate $producerGate,
    ) {}

    /**
     * @param iterable<SymbolPath> $classUniverse
     */
    public function prepareArchitecture(
        DependencyGraphInterface $graph,
        iterable $classUniverse,
        ProfilerInterface $profiler,
    ): void {
        $enabled = false;

        // Every producer that reads the prepared policy, not just the first
        // one: `architecture.unassigned-class` used to be a channel of the
        // layer-violation rule and so was covered by asking about that rule
        // alone. As a producer of its own it is not, and asking about one of
        // two left `--only-rule=architecture.unassigned-class` reaching an
        // unprepared policy. The list is the capability's, not the run's.
        foreach (ArchitectureChannels::PRODUCERS as $producerRuleName) {
            if ($this->producerGate->isEnabled($producerRuleName)) {
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
        if (!$this->producerGate->isEnabled(CircularDependencyPreparationInterface::PRODUCER_RULE_NAME)) {
            $this->circularDependencyPreparation->reset();

            return;
        }

        $profiler->start('cycles', 'pipeline');
        $this->circularDependencyPreparation->prepare($graph);
        $profiler->stop('cycles');
    }

    public function unmatchedTypeWarning(ProjectScopeJudgement $scope, ChannelPublication $publication): ?string
    {
        if (!$publication->publishes(
            ArchitectureChannels::LAYER_DECLARATION_PRODUCER_NAME,
            new FindingChannel(ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME),
            SymbolLevel::Project,
        )) {
            return null;
        }

        return $this->layerPolicyPreparation->notJudgedWarning($scope);
    }

    /**
     * @param list<SplFileInfo> $eligibleFiles
     * @param array<string, RelativePath> $publishedByInput
     *
     * @return list<AnalysisFailure>
     */
    public function inspectFiles(array $eligibleFiles, AbsolutePath $projectRoot, array $publishedByInput): array
    {
        return $this->fileSetInspection->inspect(
            $eligibleFiles,
            $projectRoot,
            $publishedByInput,
        );
    }
}
