<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

/**
 * Advice the engine adds when it refuses exactly this scalar written at a
 * node, in place of the node's general hint. `~` has no hint: it is the value
 * left unwritten, which the engine never refuses.
 */
final readonly class LiteralHint
{
    public function __construct(
        public int|float|string|bool $literal,
        public string $sentence,
    ) {}
}
