<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use Closure;

/**
 * The names a {@see MergePolicy::ByName} map accepts as keys.
 *
 * A fixed vocabulary is a dictionary like any schema key's and is judged in
 * the layer that wrote the name, with the same spelling rule. A vocabulary
 * drawn from a sibling node — `allow` keyed by the names `layers` declares —
 * can only be judged once that sibling is merged across every layer, so its
 * names are judged after the merge, exactly as written.
 */
final readonly class NameVocabulary
{
    /**
     * @param list<string> $fixed
     * @param ?Closure(mixed): list<string> $extract
     */
    private function __construct(
        private array $fixed,
        public ?string $siblingKey,
        private ?Closure $extract,
    ) {}

    /** @param list<string> $names canonical names */
    public static function fixed(array $names): self
    {
        return new self($names, null, null);
    }

    /**
     * @param string $siblingKey canonical key of a node in the same map as the named map
     * @param Closure(mixed): list<string> $extract receives the sibling's merged plain value,
     *                                              or null when no layer wrote it
     */
    public static function fromSibling(string $siblingKey, Closure $extract): self
    {
        return new self([], $siblingKey, $extract);
    }

    public function isFixed(): bool
    {
        return $this->extract === null;
    }

    /** @return list<string> */
    public function fixedNames(): array
    {
        return $this->fixed;
    }

    /** @return list<string> */
    public function namesFrom(mixed $mergedSibling): array
    {
        return $this->extract === null ? $this->fixed : ($this->extract)($mergedSibling);
    }
}
