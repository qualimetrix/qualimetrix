<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol\TraitComposition\Contract;

final readonly class TraitCompositionResult
{
    /**
     * @param array<string, TraitMethodSource> $methods Imported methods, keyed by ASCII-folded effective name
     * @param list<string> $unread
     */
    public function __construct(
        public array $methods,
        public array $unread,
    ) {}
}
