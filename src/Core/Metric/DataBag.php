<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

/**
 * Immutable container for structured non-numeric data (occurrences, findings).
 *
 * Companion to MetricBag: while MetricBag stores scalar metrics (int|float),
 * DataBag stores lists of structured entries keyed by name.
 *
 * @phpstan-type Entry array<string, scalar>
 * @phpstan-type Entries array<string, list<Entry>>
 */
final class DataBag
{
    /** @var Entries */
    private array $entries = [];

    /**
     * @param Entries $entries
     */
    public static function fromArray(array $entries): self
    {
        $result = new self();
        $result->entries = $entries;

        return $result;
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Returns a new DataBag with the given entry appended.
     *
     * @param Entry $entry
     */
    public function add(string $key, array $entry): self
    {
        $result = new self();
        $result->entries = $this->entries;
        $result->entries[$key][] = $entry;

        return $result;
    }

    /**
     * @return list<Entry>
     */
    public function get(string $key): array
    {
        return $this->entries[$key] ?? [];
    }

    public function count(string $key): int
    {
        return \count($this->entries[$key] ?? []);
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]) && $this->entries[$key] !== [];
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Merges entries from another DataBag (concatenates lists for same keys).
     */
    public function merge(self $other): self
    {
        if ($other->entries === []) {
            return $this;
        }

        if ($this->entries === []) {
            return $other;
        }

        $result = new self();
        $result->entries = $this->entries;

        foreach ($other->entries as $key => $otherEntries) {
            if (isset($result->entries[$key])) {
                $result->entries[$key] = array_merge($result->entries[$key], $otherEntries);
            } else {
                $result->entries[$key] = $otherEntries;
            }
        }

        return $result;
    }

    /**
     * @return Entries
     */
    public function all(): array
    {
        return $this->entries;
    }
}
