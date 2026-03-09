<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Duplication;

use InvalidArgumentException;

/**
 * Represents a group of identical or near-identical code blocks found in multiple locations.
 *
 * Each DuplicateBlock contains 2+ locations where the same code appears.
 * The block is characterized by its size (lines and tokens).
 */
final readonly class DuplicateBlock
{
    /**
     * @param list<DuplicateLocation> $locations At least 2 locations
     * @param int $lines Number of lines in the duplicated block
     * @param int $tokens Number of tokens in the duplicated block
     */
    public function __construct(
        public array $locations,
        public int $lines,
        public int $tokens,
    ) {
        if (\count($this->locations) < 2) {
            throw new InvalidArgumentException(
                \sprintf('DuplicateBlock requires at least 2 locations, got %d', \count($this->locations)),
            );
        }
    }

    /**
     * Returns number of occurrences (always >= 2).
     */
    public function occurrences(): int
    {
        return \count($this->locations);
    }

    /**
     * Returns the primary (first) location for reporting.
     */
    public function primaryLocation(): DuplicateLocation
    {
        return $this->locations[0];
    }

    /**
     * Returns all locations except the primary one.
     *
     * @return list<DuplicateLocation>
     */
    public function relatedLocations(): array
    {
        return \array_slice($this->locations, 1);
    }
}
