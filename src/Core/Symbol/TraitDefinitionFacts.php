<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

final readonly class TraitDefinitionFacts
{
    /**
     * @param array<string, TraitMethodSource> $ownMethods
     * @param list<TraitUseFacts> $uses
     */
    public function __construct(
        public array $ownMethods = [],
        public array $uses = [],
    ) {}
}
