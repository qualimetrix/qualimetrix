<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

/**
 * Why a name judged by a {@see NameVocabulary::predicate()} is refused, in its
 * owner's words, and — when the names it could have been are few — which.
 */
final readonly class RefusedName
{
    /** @param ?list<string> $accepted null when the accepted names are not a closed list */
    private function __construct(
        public string $summary,
        public ?array $accepted,
    ) {}

    public static function open(string $summary): self
    {
        return new self($summary, null);
    }

    /** @param list<string> $accepted */
    public static function among(string $summary, array $accepted): self
    {
        return new self($summary, $accepted);
    }
}
