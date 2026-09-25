<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use Closure;

/**
 * The names a {@see MergePolicy::ByName} map accepts as keys.
 *
 * A fixed vocabulary is a dictionary like any schema key's and is judged in
 * the layer that wrote the name, with the same spelling rule. A predicate is
 * an open grammar, judged in the layer that wrote the name, exactly as
 * written. A vocabulary drawn from a sibling node — `allow` keyed by the names
 * `layers` declares — can only be judged once that sibling is merged across
 * every layer, so its names are judged after the merge, exactly as written.
 *
 * Every written name is judged, whatever is written under it: `~` and `{}`
 * leave the entry without a body, not without a name.
 */
final readonly class NameVocabulary
{
    /**
     * @param list<string> $fixed
     * @param ?Closure(mixed): list<string> $extract
     * @param ?Closure(string, list<string>): bool $admits
     * @param ?Closure(string): ?RefusedName $predicate
     */
    private function __construct(
        private array $fixed,
        public ?string $siblingKey,
        private ?Closure $extract,
        private ?Closure $admits,
        private ?Closure $predicate,
    ) {}

    /** @param list<string> $names canonical names */
    public static function fixed(array $names): self
    {
        return new self($names, null, null, null, null);
    }

    /**
     * @param string $siblingKey canonical key of a node in the same map as the named map
     * @param Closure(mixed): list<string> $extract receives the sibling's merged plain value,
     *                                              or null when no layer wrote it
     * @param ?Closure(string $name, list<string> $known): bool $admits whether a written name refers to
     *                                                                  the extracted ones; null admits
     *                                                                  only an exact match
     */
    public static function fromSibling(string $siblingKey, Closure $extract, ?Closure $admits = null): self
    {
        return new self([], $siblingKey, $extract, $admits, null);
    }

    /** @param Closure(string $name): ?RefusedName $judge null admits the name */
    public static function predicate(Closure $judge): self
    {
        return new self([], null, null, null, $judge);
    }

    public function isFixed(): bool
    {
        return $this->siblingKey === null && $this->predicate === null;
    }

    public function isPredicate(): bool
    {
        return $this->predicate !== null;
    }

    public function isFromSibling(): bool
    {
        return $this->siblingKey !== null;
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

    /** @param list<string> $known */
    public function admits(string $name, array $known): bool
    {
        return $this->admits === null ? \in_array($name, $known, true) : ($this->admits)($name, $known);
    }

    public function refuse(string $name): ?RefusedName
    {
        return $this->predicate === null ? null : ($this->predicate)($name);
    }
}
