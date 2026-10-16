<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * One surface on its way through the comparison: the two sides' texts as the
 * stages so far have left them.
 *
 * A stage that has decided the surface — found it equal, or reported why it
 * cannot be compared — settles it, and no later stage sees it.
 */
final class SurfacePair
{
    public bool $settled = false;

    /** Whether both sides are in the order of their own producer's key; see {@see PublishedOrder}. */
    public bool $ordered = false;

    public function __construct(
        public readonly string $key,
        public readonly string $surface,
        public ?string $candidate,
        public ?string $reference,
    ) {}

    public function settle(): void
    {
        $this->settled = true;
    }
}
