<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

/** One node of the resolved configuration document. */
interface ResolvedValueInterface
{
    /** The node as plain PHP values, without provenance. */
    public function plain(): mixed;

    /**
     * The layers whose writing survives in this node, lowest precedence first.
     *
     * @return non-empty-list<Provenance>
     */
    public function contributors(): array;

    /**
     * Refuses this node's value, naming whoever is responsible for it:
     * the winning layer of a leaf, every contributor of a merged node.
     */
    public function refuse(string $summary): never;
}
