<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

/**
 * Immutable Value Object describing a single layer match for a class FQN.
 *
 * Returned by {@see LayerRegistry::resolveAll()} — one entry per layer
 * whose patterns match the FQN, in declaration order. The first entry
 * is the layer the class is assigned to; subsequent entries are
 * "shadowed" layers that would have matched if they were declared first.
 *
 * Used by:
 * - {@see \Qualimetrix\Rules\Architecture\LayerViolationRule} for the
 *   `architecture.potential-shadow` evidence-based diagnostic.
 * - {@see \Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand}
 *   (Step 6 of the follow-up plan) for per-class introspection.
 */
final readonly class LayerMatch
{
    public function __construct(
        public string $layerName,
        public string $matchingPattern,
    ) {}
}
