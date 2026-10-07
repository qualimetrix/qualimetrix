<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Observation;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;

/** Collects dependency-edge layer evidence from one prepared run. */
final readonly class EdgeEvidenceWalk
{
    public function __construct(
        private ArchitectureConfiguration $architecture,
        private AnalysisContext $context,
    ) {}

    public function collect(): EdgeWalkEvidence
    {
        $forbidden = [];
        $sourceEdges = 0;
        $targetEdges = 0;
        $classes = [];
        $undecidable = [];
        $doubted = [];
        $assignedHits = [];
        $matchedSymbols = [];
        $excludedSymbols = [];
        $excludedNames = [];
        $unansweredSymbols = [];
        $undecidedSymbols = [];
        $contendedSymbols = [];
        $ownsIfExcludedSymbols = [];

        $graph = $this->context->dependencyGraph;
        if ($graph === null) {
            return new EdgeWalkEvidence(
                forbiddenEdges: [],
                coverageState: ['sourceEdges' => 0, 'targetEdges' => 0, 'classes' => [], 'undecidable' => [], 'doubted' => []],
                assignedHits: [],
                symbolSets: ['matched' => [], 'excluded' => [], 'unanswered' => [], 'undecided' => [], 'contended' => [], 'ownsIfExcluded' => []],
                excludedNames: [],
            );
        }

        $registry = $this->architecture->registry();
        foreach ($graph->getAllDependencies() as $dependency) {
            $source = $dependency->sourceLogical();
            $target = $dependency->targetLogical();
            $fromMatches = $registry->resolveAll($source);
            $toMatches = $registry->resolveAll($target);
            $matchedSymbols = LayerEvidenceTally::matches($matchedSymbols, $fromMatches, $source->toCanonical());
            $matchedSymbols = LayerEvidenceTally::matches($matchedSymbols, $toMatches, $target->toCanonical());

            foreach ([$source, $target] as $end) {
                $excludedLayers = $registry->excludedLayers($end);
                $excludedSymbols = LayerEvidenceTally::layers($excludedSymbols, $excludedLayers, $end->toCanonical());
                if ($excludedLayers !== []) {
                    $excludedNames[$end->toCanonical()] = $end->toString();
                }
            }
            $unansweredSymbols = LayerEvidenceTally::layers(
                $unansweredSymbols,
                $registry->unansweredExcludeLayers($source),
                $source->toCanonical(),
            );
            $unansweredSymbols = LayerEvidenceTally::layers(
                $unansweredSymbols,
                $registry->unansweredExcludeLayers($target),
                $target->toCanonical(),
            );

            $fromMatch = LayerEvidenceTally::edgeEnd(
                $fromMatches,
                $source->toCanonical(),
                $source->toString(),
                $assignedHits,
                $classes,
                $sourceEdges,
            );
            $toMatch = LayerEvidenceTally::edgeEnd(
                $toMatches,
                $target->toCanonical(),
                $target->toString(),
                $assignedHits,
                $classes,
                $targetEdges,
            );

            foreach ([[$fromMatch, $source], [$toMatch, $target]] as [$match, $end]) {
                $undecidedLayers = $registry->undecidedLayers($end);
                $undecidedSymbols = LayerEvidenceTally::layers($undecidedSymbols, $undecidedLayers, $end->toCanonical());
                $contendedSymbols = LayerEvidenceTally::layers($contendedSymbols, $registry->contenders($end), $end->toCanonical());
                if ($match === null) {
                    $undecidable = LayerEvidenceTally::unanswered(
                        $undecidable,
                        $undecidedLayers,
                        $end->toCanonical(),
                        $end->toString(),
                    );
                } else {
                    $doubted = LayerEvidenceTally::unanswered(
                        $doubted,
                        $undecidedLayers,
                        $end->toCanonical(),
                        $end->toString(),
                    );
                    $ownsIfExcludedSymbols = LayerEvidenceTally::ownerIfExcluded(
                        $ownsIfExcludedSymbols,
                        $match,
                        $registry->establishedMatches($end),
                        $end->toCanonical(),
                    );
                }
            }

            if ($fromMatch !== null
                && $toMatch !== null
                && !$this->architecture->policy()->isAllowed($fromMatch->layerName, $toMatch->layerName, $dependency->type)) {
                $forbidden[] = new ForbiddenEdge($dependency, $fromMatch, $toMatch);
            }
        }

        return new EdgeWalkEvidence(
            forbiddenEdges: $forbidden,
            coverageState: [
                'sourceEdges' => $sourceEdges,
                'targetEdges' => $targetEdges,
                'classes' => $classes,
                'undecidable' => $undecidable,
                'doubted' => $doubted,
            ],
            assignedHits: $assignedHits,
            symbolSets: [
                'matched' => $matchedSymbols,
                'excluded' => $excludedSymbols,
                'unanswered' => $unansweredSymbols,
                'undecided' => $undecidedSymbols,
                'contended' => $contendedSymbols,
                'ownsIfExcluded' => $ownsIfExcludedSymbols,
            ],
            excludedNames: $excludedNames,
        );
    }
}
