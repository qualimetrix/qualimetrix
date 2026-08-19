<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/**
 * Whether a declared layer describes code that exists yet.
 *
 * {@see Pending} is the author's statement that the layer is a placeholder —
 * a module boundary declared before the module is written, or a layer emptied
 * by a refactor in flight. It is the one case where matching nothing is not a
 * mistake, so `architecture.unreachable-layer` skips such a layer and
 * `architecture.pending-layer-matched` reports it once its criteria do match.
 *
 * Declared in YAML as `pending: true` on the layer entry. An enum rather than
 * the boolean it is spelled as, because it travels through the construction
 * chain as an argument, where `true` on its own says nothing about what it
 * switches.
 *
 * Layers produced by template expansion are always {@see Active}: an instance
 * exists only because a tuple was observed in the analysed code.
 */
enum LayerLifecycle
{
    case Active;
    case Pending;

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
