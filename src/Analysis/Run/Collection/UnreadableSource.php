<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Collection;

/** A source entry refused before parsing. */
final readonly class UnreadableSource
{
    public function __construct(public string $reason) {}
}
