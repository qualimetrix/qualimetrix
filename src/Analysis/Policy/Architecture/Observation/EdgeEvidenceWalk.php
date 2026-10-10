<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Observation;

use Generator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Collects dependency-edge layer evidence from one prepared run.
 *
 * @qmx-threshold coupling.instability warning=0.89 -- The policy walk joins graph, assignment and population evidence for its owning collector; its few callers are intentional.
 */
final readonly class EdgeEvidenceWalk
{
    public function __construct(
        private ArchitectureConfiguration $architecture,
        private AnalysisContext $context,
    ) {}

    /** @return list<PopulationGate> */
    public static function populationGates(): array
    {
        $channel = new FindingChannel(ArchitectureChannels::PRODUCER_RULE_NAME);
        return [
            new PopulationGate('graph-available', $channel, SymbolLevel::Class_, 'dependency-edge', new ContextGuard('graphAvailable'), 'The dependency graph is unavailable.', 'invocation'),
            new PopulationGate('source-assigned', $channel, SymbolLevel::Class_, 'dependency-edge', new ContextGuard('edgeSourceAssigned'), 'The source has no assigned layer.'),
            new PopulationGate('target-assigned', $channel, SymbolLevel::Class_, 'dependency-edge', new ContextGuard('edgeTargetAssigned'), 'The target has no assigned layer.'),
        ];
    }

    public static function channelDeclaration(): ChannelDeclaration
    {
        return ChannelDeclaration::occurrence(SymbolLevel::Class_)->readingRunEvidence()->withGates(
            new PopulationGate('prepared-evidence', new FindingChannel(ArchitectureChannels::PRODUCER_RULE_NAME), SymbolLevel::Class_, 'dependency-edge', new ContextGuard('preparedEvidenceAvailable'), 'Prepared layer evidence is unavailable.', 'invocation'),
            ...self::populationGates(),
        );
    }

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

        $declaration = self::channelDeclaration();
        $graph = $this->context->dependencyGraph;
        if ($graph === null) {
            $this->context->admit(ArchitectureChannels::PRODUCER_RULE_NAME, new FindingChannel(ArchitectureChannels::PRODUCER_RULE_NAME), SymbolLevel::Class_, PopulationIdentity::invocation(ArchitectureChannels::PRODUCER_RULE_NAME), $declaration, (static function (): Generator {
                yield GateInput::context('preparedEvidenceAvailable', true);
                yield GateInput::context('graphAvailable', false);
            })());
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

                    continue;
                }
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

            $forbiddenEdge = $this->forbiddenEdge($dependency, $fromMatch, $toMatch, $declaration);
            if ($forbiddenEdge !== null) {
                $forbidden[] = $forbiddenEdge;
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

    private function forbiddenEdge(
        Dependency $dependency,
        ?LayerMatch $from,
        ?LayerMatch $to,
        ChannelDeclaration $declaration,
    ): ?ForbiddenEdge {
        $identity = PopulationIdentity::selector(json_encode([
            $dependency->sourceLogical()->toCanonical(),
            $dependency->targetLogical()->toCanonical(),
            $dependency->type->value,
        ], \JSON_THROW_ON_ERROR), 'dependency-edge');
        if (!$this->context->admit(ArchitectureChannels::PRODUCER_RULE_NAME, new FindingChannel(ArchitectureChannels::PRODUCER_RULE_NAME), SymbolLevel::Class_, $identity, $declaration, (static function () use ($from, $to): Generator {
            yield GateInput::context('preparedEvidenceAvailable', true);
            yield GateInput::context('graphAvailable', true);
            yield GateInput::context('edgeSourceAssigned', $from !== null);
            yield GateInput::context('edgeTargetAssigned', $to !== null);
        })())) {
            return null;
        }
        \assert($from !== null && $to !== null);
        if ($this->architecture->policy()->isAllowed($from->layerName, $to->layerName, $dependency->type)) {
            return null;
        }

        return new ForbiddenEdge($dependency, $from, $to);
    }
}
