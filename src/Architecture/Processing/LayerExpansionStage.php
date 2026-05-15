<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;

/**
 * Expands a mixed list of {@see LayerDefinition} and
 * {@see TemplateLayerDefinition} entries into the concrete declaration-order
 * layer list consumed by {@see \Qualimetrix\Architecture\Domain\Layer\LayerRegistry}.
 *
 * Plugs into {@see \Qualimetrix\Analysis\Pipeline\AnalysisPipeline} between
 * the collection and rule-execution phases (after {@code CollectionOrchestrator}
 * has produced the class set and the dependency graph, but before
 * {@code MetricEnricher} runs). Result is written into the existing
 * {@see \Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder} so
 * the rule layer reads it without any change to the {@code AnalysisContext}
 * surface.
 *
 * **Observed-tuple expansion (NOT cartesian).** For each template, the stage
 * walks the project's class set, applies all of the template's criteria
 * (capture-producing AND non-capturing per D7), and collects the distinct
 * observed binding tuples. One concrete {@see LayerDefinition} is produced
 * per tuple, named by substituting the binding values into
 * {@see TemplateLayerDefinition::$nameTemplate}. A two-variable template
 * over a project where {@code tenant=AcmeCorp} and {@code module=Order} but
 * never together produces zero layers, not the cartesian {@code AcmeCorp×Order}
 * combinations.
 *
 * **Failure modes** (all surface as {@see LayerExpansionException}):
 * - Cumulative expansion exceeds {@code architecture.max_expanded_layers}.
 * - A concrete name produced by substitution collides with a static layer
 *   name (or another template-expanded name).
 * - Substitution produces an invalid name (binding contains a character
 *   the relaxed expansion-mode regex does not accept).
 *
 * **Collaborator decomposition (Phase 4.1).** The two heavy concerns
 * — walking the class set to gather observed tuples and instantiating a
 * concrete {@see LayerDefinition} from a tuple — live in
 * {@see TupleExtractor} and {@see LayerInstantiator} respectively. This
 * class is the orchestrator: it iterates entries, calls the helpers, and
 * enforces the cumulative-expansion ceiling + uniqueness invariant.
 *
 * **Empty-template signal.** Templates that observe zero tuples are
 * collected into {@see LayerExpansionResult::$emptyTemplateNames}; the
 * {@see \Qualimetrix\Architecture\Rules\LayerViolationRule} drains the list
 * into one {@code architecture.empty-template} warning per name at the end
 * of the run.
 */
final class LayerExpansionStage
{
    private readonly TupleExtractor $tupleExtractor;

    private readonly LayerInstantiator $layerInstantiator;

    public function __construct(
        ?TupleExtractor $tupleExtractor = null,
        ?LayerInstantiator $layerInstantiator = null,
    ) {
        $this->tupleExtractor = $tupleExtractor ?? new TupleExtractor();
        $this->layerInstantiator = $layerInstantiator ?? new LayerInstantiator();
    }

    /**
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries Mixed
     *                                                               layer-and-template
     *                                                               entries
     *                                                               in
     *                                                               declaration
     *                                                               order.
     * @param ClassSet $classes Project class set + context resolver.
     * @param int $maxExpansion Hard ceiling on cumulative template-produced
     *                          layers ({@code architecture.max_expanded_layers}).
     *                          Must be positive.
     *
     * @throws LayerExpansionException
     */
    public function expand(array $entries, ClassSet $classes, int $maxExpansion): LayerExpansionResult
    {
        if ($maxExpansion < 1) {
            throw new LayerExpansionException(\sprintf(
                'LayerExpansionStage: max-expansion ceiling must be >= 1, got %d.',
                $maxExpansion,
            ));
        }

        $expandedLayers = [];
        $emptyTemplates = [];
        /** @var array<string, array{source: string, origin: string}> */
        $seenNames = [];
        $totalTemplateExpansions = 0;

        foreach ($entries as $entry) {
            if ($entry instanceof LayerDefinition) {
                self::recordName($seenNames, $entry->name(), 'static layer', $entry->name());
                $expandedLayers[] = $entry;

                continue;
            }

            $tuples = $this->tupleExtractor->collect($entry, $classes);

            if ($tuples === []) {
                $emptyTemplates[] = $entry->nameTemplate();

                continue;
            }

            $thisTemplateCount = \count($tuples);
            $totalTemplateExpansions += $thisTemplateCount;
            if ($totalTemplateExpansions > $maxExpansion) {
                throw new LayerExpansionException(\sprintf(
                    'LayerExpansionStage: template "%s" added %d layers (cumulative %d across all templates), '
                    . 'exceeding the architecture.max_expanded_layers ceiling of %d. '
                    . 'Raise the ceiling via architecture.max_expanded_layers in your config, '
                    . 'or tighten the template patterns to reduce the observed binding set.',
                    $entry->nameTemplate(),
                    $thisTemplateCount,
                    $totalTemplateExpansions,
                    $maxExpansion,
                ));
            }

            foreach ($tuples as $tuple) {
                $concreteLayer = $this->layerInstantiator->instantiate($entry, $tuple);
                self::recordName(
                    $seenNames,
                    $concreteLayer->name(),
                    'template "' . $entry->nameTemplate() . '"',
                    $entry->nameTemplate(),
                );
                $expandedLayers[] = $concreteLayer;
            }
        }

        return new LayerExpansionResult($expandedLayers, $emptyTemplates);
    }

    /**
     * @param array<string, array{source: string, origin: string}> $seenNames
     *
     * @param-out array<string, array{source: string, origin: string}> $seenNames
     */
    private static function recordName(array &$seenNames, string $name, string $source, string $origin): void
    {
        if (isset($seenNames[$name])) {
            throw new LayerExpansionException(\sprintf(
                'LayerExpansionStage: layer name "%s" produced by %s "%s" collides with %s "%s". '
                . 'Each expanded layer name must be unique — rename one of the templates or static layers.',
                $name,
                $source,
                $origin,
                $seenNames[$name]['source'],
                $seenNames[$name]['origin'],
            ));
        }

        $seenNames[$name] = ['source' => $source, 'origin' => $origin];
    }
}
