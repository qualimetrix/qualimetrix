<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

/**
 * @phpstan-type TraitAlias array{trait: string|null, method: string, alias: string|null, visibility: 'public'|'protected'|'private'|null}
 * @phpstan-type TraitPrecedence array{trait: string, method: string, insteadOf: list<string>}
 */
final readonly class TraitUseFacts
{
    /**
     * @param list<string> $traits
     * @param list<TraitAlias> $aliases
     * @param list<TraitPrecedence> $precedences
     */
    public function __construct(
        public array $traits,
        public array $aliases = [],
        public array $precedences = [],
    ) {}
}
