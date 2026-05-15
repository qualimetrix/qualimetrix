<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use InvalidArgumentException;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
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
 * Both lookups share a single cache keyed by {@see SymbolPath::toCanonical()}:
 * the full {@see LayerMatch} list is computed once and stored, and
 * {@see resolveLayer()} reads the first entry off that list. A class queried
 * by both methods therefore walks the criteria at most once. The cache is the
 * only mutable state on the registry (which is therefore final but not
 * readonly).
 *
 * The registry holds a {@see ClassContextFactory} that produces the
 * {@see ClassContext} consumed by {@see LayerDefinition::matches()}. The
 * factory is per-analysis-run state: the rule binds the dependency graph at
 * the start of every {@see \Qualimetrix\Rules\Architecture\LayerViolationRule::analyze()}
 * call via {@see bindGraph()} and clears the registry's match cache via
 * {@see clearCache()} so the new graph's data is picked up. Before
 * {@see bindGraph()} is called the factory operates in no-graph mode (e.g.
 * during config load or in the {@code debug:layer-assignment} command) and
 * only the {@code patterns} and {@code suffix} criteria can fire.
 *
 * There is intentionally no specificity scoring, no collision detection,
 * and no exception class for ambiguity — declaration order is the user's
 * tool to express intent, and the engine does not second-guess it. The
 * {@see \Qualimetrix\Rules\Architecture\LayerViolationRule} emits
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
     * Shared cache for {@see resolveLayer()} and {@see resolveAll()}. Keyed by
     * {@see SymbolPath::toCanonical()}.
     *
     * Each value is the complete list of {@see LayerMatch} entries in
     * declaration order. Empty list means the class matches no layer.
     *
     * @var array<string, list<LayerMatch>>
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
     * Returns the underlying {@see ClassContextFactory} so callers (chiefly
     * {@see \Qualimetrix\Rules\Architecture\LayerViolationRule}) can bind it
     * to the analysis-run dependency graph.
     */
    public function contextFactory(): ClassContextFactory
    {
        return $this->contextFactory;
    }

    /**
     * Convenience: forwards to {@see ClassContextFactory::bindGraph()} and
     * invalidates the match cache so subsequent lookups pick up the new
     * graph's data.
     */
    public function bindGraph(?DependencyGraphInterface $graph): void
    {
        $this->contextFactory->bindGraph($graph);
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
     * This is the hot path for {@see \Qualimetrix\Rules\Architecture\LayerViolationRule}
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
        $cacheKey = $class->toCanonical();
        if (\array_key_exists($cacheKey, $this->matchCache)) {
            return $this->matchCache[$cacheKey];
        }

        $context = $this->contextFactory->build($class);
        if ($context->fqn === '') {
            return $this->matchCache[$cacheKey] = [];
        }

        $matches = [];
        foreach ($this->layers as $layer) {
            $result = $layer->matches($context);
            if (!$result->matched) {
                continue;
            }
            $matches[] = new LayerMatch($layer->name(), $result->matchedCriteria);
        }

        return $this->matchCache[$cacheKey] = $matches;
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
