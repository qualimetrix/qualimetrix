<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Observation;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use WeakMap;

/** Collects and memoizes one run's class-side and edge-side layer evidence. */
final class LayerEvidenceCollector
{
    /** @var WeakMap<AnalysisContext, list<LayerEvidence|null>> */
    private WeakMap $memo;

    public function __construct(
        private readonly RuleOptionsInterface $layerViolation,
        private readonly RuleOptionsInterface $unassignedClass,
        private readonly RuleOptionsInterface $layerDeclaration,
        private readonly ArchitecturePolicy $processor,
    ) {
        $this->memo = new WeakMap();
    }

    public function collect(AnalysisContext $context): ?LayerEvidence
    {
        $memoized = $this->memo[$context] ?? null;
        if ($memoized !== null) {
            return $memoized[0];
        }

        $evidence = $this->walk($context);
        $this->memo[$context] = [$evidence];

        return $evidence;
    }

    private function walk(AnalysisContext $context): ?LayerEvidence
    {
        if (!$this->hasEnabledConsumer()) {
            return null;
        }
        $architecture = $this->preparedArchitecture();
        if ($architecture->isEmpty()) {
            return null;
        }

        $classWalk = (new ClassEvidenceWalk($architecture, $context, $this->unassignedClass))->collect();
        $edgeWalk = (new EdgeEvidenceWalk($architecture, $context))->collect();
        $coverageState = $edgeWalk->coverageState;
        $coverageState['classes'] += $classWalk->uncoveredClasses;
        $coverageState['undecidableOutsidePaths'] = array_diff_key(
            $coverageState['undecidable'],
            $classWalk->undecidableClasses,
        );
        $coverageState['undecidable'] += $classWalk->undecidableClasses;
        $coverageState['doubtedOutsidePaths'] = array_diff_key(
            $coverageState['doubted'],
            $classWalk->doubtedClasses,
        );
        $coverageState['doubted'] += $classWalk->doubtedClasses;

        $symbolSets = $classWalk->symbolSets;
        foreach ($symbolSets as $column => $sets) {
            $symbolSets[$column] = LayerEvidenceTally::mergeSymbols($sets, $edgeWalk->symbolSets[$column]);
        }

        return new LayerEvidence(
            architecture: $architecture,
            forbiddenEdges: $edgeWalk->forbiddenEdges,
            assignedHits: LayerEvidenceTally::mergeHits($classWalk->assignedHits, $edgeWalk->assignedHits),
            symbolSets: $symbolSets,
            shadowEvidence: $classWalk->shadowEvidence,
            unassigned: ['classes' => $classWalk->uncoveredClasses, 'analysed' => $classWalk->analysedDeclarations],
            coverageState: $coverageState,
            excludedNames: $classWalk->excludedNames + $edgeWalk->excludedNames,
            precedenceEvidence: $classWalk->precedenceEvidence,
            assignedClassHits: $classWalk->assignedHits,
        );
    }

    private function hasEnabledConsumer(): bool
    {
        return $this->layerViolation->isEnabled()
            || $this->unassignedClass->isEnabled()
            || $this->layerDeclaration->isEnabled();
    }

    private function preparedArchitecture(): ArchitectureConfiguration
    {
        return $this->processor->getPreparedConfiguration()
            ?? throw new LogicException(
                'LayerEvidenceCollector::collect() reached an unprepared ArchitecturePolicy. The layer verdicts'
                . ' read a configuration prepared for the run; a producer whose policy was never prepared must be'
                . ' left out of the selection, not asked for evidence.',
            );
    }
}
