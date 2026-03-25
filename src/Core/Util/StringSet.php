<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * An immutable set of unique strings.
 *
 * Provides efficient O(1) lookups and deduplication for string collections.
 * Useful for counting unique dependencies in coupling metrics.
 *
 * @implements IteratorAggregate<int, string>
 */
final class StringSet implements Countable, IteratorAggregate
{
    /**
     * Internal storage using string keys for O(1) lookup.
     *
     * @var array<string, true>
     */
    private array $items = [];

    /**
     * Creates a new StringSet with the given string added.
     */
    public function add(string $value): self
    {
        if (isset($this->items[$value])) {
            return $this;
        }

        $result = new self();
        $result->items = $this->items;
        $result->items[$value] = true;

        return $result;
    }

    /**
     * Creates a new StringSet with multiple strings added.
     *
     * @param iterable<string> $values
     */
    public function addAll(iterable $values): self
    {
        $result = $this;

        foreach ($values as $value) {
            $result = $result->add($value);
        }

        return $result;
    }

    /**
     * Returns true if the set contains the given string.
     */
    public function contains(string $value): bool
    {
        return isset($this->items[$value]);
    }

    /**
     * Returns the number of unique strings in the set.
     */
    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * Returns true if the set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Returns all strings as an indexed array.
     *
     * @return array<int, string>
     */
    public function toArray(): array
    {
        return array_keys($this->items);
    }

    /**
     * Returns a new set containing only strings that pass the filter.
     *
     * @param callable(string): bool $predicate
     */
    public function filter(callable $predicate): self
    {
        $result = new self();

        foreach ($this->items as $value => $_) {
            if ($predicate($value)) {
                $result->items[$value] = true;
            }
        }

        return $result;
    }

    /**
     * Returns a new set containing the union of this set and another.
     */
    public function union(self $other): self
    {
        $result = new self();
        $result->items = array_merge($this->items, $other->items);

        return $result;
    }

    /**
     * Returns a new set containing the intersection of this set and another.
     */
    public function intersect(self $other): self
    {
        $result = new self();
        $result->items = array_intersect_key($this->items, $other->items);

        return $result;
    }

    /**
     * Returns a new set containing strings in this set but not in another.
     */
    public function diff(self $other): self
    {
        $result = new self();
        $result->items = array_diff_key($this->items, $other->items);

        return $result;
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->items as $value => $_) {
            yield $value;
        }
    }

    /**
     * Creates a StringSet from an array of strings.
     *
     * @param array<string> $values
     */
    public static function fromArray(array $values): self
    {
        $result = new self();

        foreach ($values as $value) {
            $result->items[$value] = true;
        }

        return $result;
    }
}
