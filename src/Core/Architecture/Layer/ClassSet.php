<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Immutable view of the project's discovered class set, paired with the
 * {@see ClassContextFactory} that resolves per-class context (attributes,
 * interfaces, parent classes).
 *
 * Built by {@see \Qualimetrix\Analysis\Pipeline\AnalysisPipeline} once
 * collection and dependency-graph construction have completed; consumed by
 * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage} to walk the
 * class set for each {@see TemplateLayerDefinition} and collect observed
 * binding tuples.
 *
 * Lives in {@code Core/Architecture/Layer/} (next to the other layer
 * primitives) because both the {@code Analysis/} expansion stage and any
 * future architecture-aware code can consume it without crossing the Core
 * boundary.
 *
 * **No internal caching.** Repeated {@see contextFor()} calls delegate
 * straight to the factory, which already memoises per-FQN contexts. The
 * VO is therefore safe to construct cheaply.
 */
final readonly class ClassSet
{
    /**
     * @param list<SymbolPath> $classes Class symbol paths discovered during
     *                                  the collection phase. Order is the
     *                                  call-site's responsibility; the
     *                                  expansion stage sorts derived
     *                                  binding tuples lexicographically.
     */
    public function __construct(
        public array $classes,
        public ClassContextFactory $contextFactory,
    ) {}

    /**
     * Builds (or returns the memoised) {@see ClassContext} for the symbol.
     */
    public function contextFor(SymbolPath $class): ClassContext
    {
        return $this->contextFactory->build($class);
    }

    /**
     * @return list<SymbolPath>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function isEmpty(): bool
    {
        return $this->classes === [];
    }
}
