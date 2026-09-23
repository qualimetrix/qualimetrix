<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Owns the full ordered set of {@see LayerDefinition} instances declared by
 * configuration and resolves classes to their owning layer.
 *
 * Resolution semantics — **declaration order, first match wins**:
 * - {@see resolveLayer()} iterates definitions in declared order and returns
 *   the name of the first layer whose membership criteria match (or null if
 *   no layer matches). This is the hot path used during dependency-edge
 *   analysis.
 * - {@see resolveAll()} returns every layer whose criteria match, in
 *   declaration order. The first entry is the assignment; the rest are
 *   layers that would have matched if they were declared earlier. Used by
 *   evidence-based shadow detection and the debug command.
 *
 * - {@see excludedLayers()} returns the layers whose positive criteria
 *   matched and whose `exclude:` clause then removed the class. It is a
 *   second exit of the very same walk, not a second walk, and it changes
 *   nothing about assignment.
 * - {@see undecidedLayers()} returns the layers the run could not answer for
 *   the class. Third exit of the same walk, and it changes nothing about
 *   assignment either — see its own note for why that is deliberate.
 *
 * All four lookups share a single cache keyed by
 * {@see SymbolPath::toCanonical()}: both outputs of the walk are computed once
 * and stored together, and {@see resolveLayer()} reads the first entry off the
 * match list. A class queried by every method therefore walks the criteria at
 * most once. The cache is the
 * only mutable state on the registry (which is therefore final but not
 * readonly).
 *
 * The registry holds a {@see ClassContextFactory} that produces the
 * {@see ClassContext} consumed by {@see LayerDefinition::matches()}. The
 * factory is per-analysis-run state:
 * {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::prepare()}
 * binds the run's dependency graph via {@see bindGraph()}, which also drops
 * the match cache so the new graph's data is picked up. Before
 * {@see bindGraph()} is called the factory operates in no-graph mode (config
 * load, or a registry built without a run behind it): {@code patterns} and
 * {@code suffix} still resolve, and a layer declaring {@code attributes},
 * {@code implements} or {@code extends} refuses instead of resolving to
 * nothing.
 *
 * There is intentionally no specificity scoring, no collision detection,
 * and no exception class for ambiguity — declaration order is the user's
 * tool to express intent, and the engine does not second-guess it. The
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule} emits
 * `architecture.unreachable-layer` and `architecture.potential-shadow`
 * info-level diagnostics to surface misordered or overlapping declarations.
 */
final class LayerRegistry
{
    /**
     * @var list<LayerDefinition>
     */
    private array $layers;

    private ClassContextFactory $contextFactory;

    /**
     * Shared cache for {@see resolveLayer()}, {@see resolveAll()} and
     * {@see excludedLayers()}. Keyed by {@see SymbolPath::toCanonical()}.
     *
     * Each value carries BOTH outputs of the one walk over the layer list:
     * `matches`, the complete list of {@see LayerMatch} entries in declaration
     * order (empty means the class matches no layer), `excluded`, the
     * names of the layers whose positive criteria matched but whose
     * `exclude:` clause then removed the class, `undecided`, the names of
     * the layers the run could not answer either way, and `chainStopsAt`, where
     * the inheritance chain stopped when any layer went unanswered. One entry rather than
     * parallel arrays because separate caches drift apart at every early return
     * and at {@see clearCache()}: a lookup that found one populated and the
     * others not would report an exclusion that never happened.
     *
     * @var array<string, array{matches: list<LayerMatch>, excluded: list<string>, undecided: list<string>, chainStopsAt: list<string>}>
     */
    private array $matchCache = [];

    /**
     * @param list<LayerDefinition> $layers Layer definitions in declaration order;
     *                                      layer names must be unique.
     * @param ClassContextFactory|null $contextFactory Optional factory injection
     *                                                 for tests / DI; defaults
     *                                                 to a fresh no-graph instance.
     *
     * @throws InvalidArgumentException If two layers share the same name.
     */
    public function __construct(array $layers, ?ClassContextFactory $contextFactory = null)
    {
        $seenNames = [];
        foreach ($layers as $layer) {
            $name = $layer->name();
            if (isset($seenNames[$name])) {
                throw new InvalidArgumentException(\sprintf(
                    'Duplicate layer name "%s" — each layer must have a unique identifier.',
                    $name,
                ));
            }
            $seenNames[$name] = true;
        }

        $this->layers = $layers;
        $this->contextFactory = $contextFactory ?? new ClassContextFactory();
    }

    /**
     * Returns the underlying {@see ClassContextFactory} so a caller can hand
     * the same instance to something that reads contexts outside the registry
     * — which is what {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::prepare()}
     * does for template expansion, and why a second factory beside this one
     * is always a defect.
     */
    public function contextFactory(): ClassContextFactory
    {
        return $this->contextFactory;
    }

    /**
     * Convenience: forwards to {@see ClassContextFactory::bindGraph()} and
     * invalidates the match cache so subsequent lookups pick up the new
     * graph's data.
     *
     * @param iterable<SymbolPath>|null $analysedClasses The run's analysed
     *                                                   declarations, forwarded
     *                                                   verbatim — see
     *                                                   {@see ClassContextFactory::bindGraph()}
     *                                                   for what omitting them
     *                                                   costs.
     */
    public function bindGraph(?DependencyGraphInterface $graph, ?iterable $analysedClasses = null): void
    {
        $this->contextFactory->bindGraph($graph, $analysedClasses);
        $this->matchCache = [];
    }

    /**
     * Drops cached layer matches. Useful when the registry instance is reused
     * across analysis runs (e.g. test fixtures sharing a configuration object)
     * and the underlying graph data may have changed.
     */
    public function clearCache(): void
    {
        $this->matchCache = [];
    }

    /**
     * Returns the name of the first layer (in declaration order) whose
     * membership criteria match the class, or null if no layer matches.
     *
     * This is the hot path for {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule}
     * — called once per dependency-edge endpoint.
     */
    public function resolveLayer(SymbolPath $class): ?string
    {
        $matches = $this->resolveAll($class);

        return $matches === [] ? null : $matches[0]->layerName;
    }

    /**
     * Returns every layer whose membership criteria match the class, in
     * declaration order.
     *
     * Returns an empty list when no layer matches. The first entry is the
     * actual assignment; subsequent entries are layers that would have matched
     * had they been declared earlier (used by `architecture.potential-shadow`
     * and the debug command).
     *
     * @return list<LayerMatch>
     */
    public function resolveAll(SymbolPath $class): array
    {
        return $this->walk($class)['matches'];
    }

    /**
     * Returns the names of every layer whose positive criteria matched the
     * class and whose `exclude:` clause then removed it, in declaration
     * order.
     *
     * The second exit of the same cached walk {@see resolveAll()} reads, not a
     * second walk: the two answers are produced by one pass over the layer
     * list and stored together. Membership is unaffected — an excluded layer
     * is absent from {@see resolveAll()} exactly as it always was.
     *
     * `architecture.unmatched-exclude` is the only consumer, and it needs this
     * because a class the clause removed and a class the positive criteria
     * never caught are the same "layer is not in the list" from the outside.
     *
     * @return list<string> layer names
     */
    public function excludedLayers(SymbolPath $class): array
    {
        return $this->walk($class)['excluded'];
    }

    /**
     * Returns the names of every layer whose membership the run could not
     * decide for this symbol, in declaration order.
     *
     * The third exit of the same cached walk, for the same reason
     * {@see excludedLayers()} is the second. `architecture.coverage-gap` and
     * `debug:layer-assignment` are the consumers: a symbol in nobody's layer
     * because the run answered every criterion is a hole the author closes by
     * writing a layer, a symbol in nobody's layer because its inheritance
     * chain left the analysed set is not, and an assignment that stands on a
     * layer the run could not fully answer is a doubt the reader must see.
     *
     * **Assignment is unaffected on purpose.** Neither an undecidable layer
     * declared before one that matched nor an undecidable `exclude:` on the
     * matching layer itself withdraws the match, even though a strictly
     * three-valued reading would make the assignment unknown. Withdrawing it
     * would leave the class in no layer, so no allow-list would judge its edges
     * and real violations would stop being reported — trading a wrong answer
     * for a missing one. The match stands and the doubt is published beside
     * it; a layer whose exclude went unanswered is named in both lists.
     *
     * @return list<string> layer names
     */
    public function undecidedLayers(SymbolPath $class): array
    {
        return $this->walk($class)['undecided'];
    }

    /**
     * Returns where the class's inheritance chain stopped because the run did
     * not read the declaration there — the subject's own FQN when it was not
     * analysed — and nothing when every layer was answered.
     *
     * A reader told only that a layer could not be answered cannot tell
     * which boundary to move; this names it.
     *
     * @return list<string> FQNs
     */
    public function chainStopsAt(SymbolPath $class): array
    {
        return $this->walk($class)['chainStopsAt'];
    }

    /**
     * @return array{matches: list<LayerMatch>, excluded: list<string>, undecided: list<string>, chainStopsAt: list<string>}
     */
    private function walk(SymbolPath $class): array
    {
        $cacheKey = $class->toCanonical();
        if (\array_key_exists($cacheKey, $this->matchCache)) {
            return $this->matchCache[$cacheKey];
        }

        $context = $this->contextFactory->build($class);
        if ($context->fqn === '') {
            return $this->matchCache[$cacheKey] = ['matches' => [], 'excluded' => [], 'undecided' => [], 'chainStopsAt' => []];
        }

        $matches = [];
        $excluded = [];
        $undecided = [];
        foreach ($this->layers as $layer) {
            $result = $layer->matches($context);
            if ($result->isExcluded()) {
                $excluded[] = $layer->name();

                continue;
            }
            // A doubted match lands in both lists: the class is a member and
            // the run could not fully establish it.
            if ($result->undecided) {
                $undecided[] = $layer->name();
            }
            if ($result->matched) {
                $matches[] = new LayerMatch($layer->name(), $result->matchedCriteria);
            }
        }

        return $this->matchCache[$cacheKey] = [
            'matches' => $matches,
            'excluded' => $excluded,
            'undecided' => $undecided,
            'chainStopsAt' => $undecided === [] ? [] : $context->chainStopsAt(),
        ];
    }

    /**
     * Returns layer names in **declaration order** (NOT alphabetically
     * sorted). The order is meaningful — it is the user's disambiguation
     * tool and the factory's cross-validation reference.
     *
     * @return list<string>
     */
    public function layerNames(): array
    {
        return array_map(static fn(LayerDefinition $layer): string => $layer->name(), $this->layers);
    }

    public function isEmpty(): bool
    {
        return $this->layers === [];
    }

    /**
     * @return list<LayerDefinition>
     */
    public function definitions(): array
    {
        return $this->layers;
    }
}
