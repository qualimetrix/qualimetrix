<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Observation;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ShadowExemption;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Collects class-side layer evidence from one prepared run. */
final readonly class ClassEvidenceWalk
{
    public function __construct(
        private ArchitectureConfiguration $architecture,
        private AnalysisContext $context,
        private RuleOptionsInterface $unassignedClass,
    ) {}

    public function collect(): ClassWalkEvidence
    {
        $registry = $this->architecture->registry();
        $materializeUncovered = $this->architecture->coverage() !== CoverageMode::Ignore
            || $this->unassignedClass->isEnabled();
        $assignedHits = [];
        $matchedSymbols = [];
        $excludedSymbols = [];
        $excludedNames = [];
        $unansweredSymbols = [];
        $undecidedSymbols = [];
        $contendedSymbols = [];
        $ownsIfExcludedSymbols = [];
        foreach ($registry->layerNames() as $layerName) {
            $assignedHits[$layerName] = 0;
            $matchedSymbols[$layerName] = [];
            $excludedSymbols[$layerName] = [];
            $unansweredSymbols[$layerName] = [];
        }

        $shadowEvidence = [];
        $precedenceEvidence = [];
        $uncoveredClasses = [];
        $undecidableClasses = [];
        $doubtedClasses = [];
        $analysedDeclarations = 0;

        foreach ($this->context->metrics->all(SymbolLevel::Class_) as $classSymbol) {
            $analysedDeclarations++;
            $path = $classSymbol->symbolPath;
            $canonical = $path->toCanonical();
            $display = $path->toString();
            $matches = $registry->resolveAll($path);
            $excludedLayers = $registry->excludedLayers($path);
            $excludedSymbols = LayerEvidenceTally::layers($excludedSymbols, $excludedLayers, $canonical);
            if ($excludedLayers !== []) {
                $excludedNames[$canonical] = $display;
            }
            $unansweredSymbols = LayerEvidenceTally::layers(
                $unansweredSymbols,
                $registry->unansweredExcludeLayers($path),
                $canonical,
            );
            $undecidedLayers = $registry->undecidedLayers($path);
            $undecidedSymbols = LayerEvidenceTally::layers($undecidedSymbols, $undecidedLayers, $canonical);
            $contendedSymbols = LayerEvidenceTally::layers($contendedSymbols, $registry->contenders($path), $canonical);

            if ($matches === []) {
                if ($materializeUncovered) {
                    $uncoveredClasses[$canonical] = $display;
                }
                $undecidableClasses = LayerEvidenceTally::unanswered(
                    $undecidableClasses,
                    $undecidedLayers,
                    $canonical,
                    $display,
                );

                continue;
            }

            $assigned = $matches[0];
            $doubtedClasses = LayerEvidenceTally::unanswered(
                $doubtedClasses,
                $undecidedLayers,
                $canonical,
                $display,
            );
            $assignedHits[$assigned->layerName] = ($assignedHits[$assigned->layerName] ?? 0) + 1;
            $matchedSymbols = LayerEvidenceTally::matches($matchedSymbols, $matches, $canonical);

            $established = $registry->establishedMatches($path);
            $ownsIfExcludedSymbols = LayerEvidenceTally::ownerIfExcluded(
                $ownsIfExcludedSymbols,
                $assigned,
                $established,
                $canonical,
            );
            self::recordShadows($established, $display, $canonical, $shadowEvidence, $precedenceEvidence);
        }

        return new ClassWalkEvidence(
            assignedHits: $assignedHits,
            symbolSets: [
                'matched' => $matchedSymbols,
                'excluded' => $excludedSymbols,
                'unanswered' => $unansweredSymbols,
                'undecided' => $undecidedSymbols,
                'contended' => $contendedSymbols,
                'ownsIfExcluded' => $ownsIfExcludedSymbols,
            ],
            shadowEvidence: $shadowEvidence,
            uncoveredClasses: $uncoveredClasses,
            analysedDeclarations: $analysedDeclarations,
            undecidableClasses: $undecidableClasses,
            doubtedClasses: $doubtedClasses,
            excludedNames: $excludedNames,
            precedenceEvidence: $precedenceEvidence,
        );
    }

    /**
     * @param list<LayerMatch> $established
     * @param array<string, array<string, list<ShadowedClass>>> $shadowEvidence
     * @param array<string, array<string, array<string, ShadowedClass>>> $precedenceEvidence
     */
    private static function recordShadows(
        array $established,
        string $display,
        string $canonical,
        array &$shadowEvidence,
        array &$precedenceEvidence,
    ): void {
        foreach (LayerShadowing::verdicts($established) as $verdict) {
            $entry = new ShadowedClass(
                $display,
                $verdict->earlier->primaryCriterion(),
                $verdict->later->primaryCriterion(),
            );
            if ($verdict->exemption === null) {
                $shadowEvidence[$verdict->earlier->layerName][$verdict->later->layerName][] = $entry;
            } elseif ($verdict->exemption === ShadowExemption::NonPatternPrecedence) {
                $precedenceEvidence[$verdict->later->layerName][$verdict->earlier->layerName][$canonical] = $entry;
            }
        }
    }
}
