<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use InvalidArgumentException;

/**
 * Represents a group of identical or near-identical code blocks found in multiple locations.
 *
 * Each DuplicateBlock contains 2+ locations where the same code appears.
 * The block is characterized by its size (lines and tokens).
 */
final readonly class DuplicateBlock
{
    /** @var list<DuplicateLocation> */
    public array $locations;

    /**
     * @param list<DuplicateLocation> $locations At least 2 locations (sorted deterministically)
     * @param int $lines Number of lines in the duplicated block
     * @param int $tokens Number of tokens in the duplicated block
     * @param string $contentHash Full SHA-256 of the normalized matched token sequence and token count
     * @param string|null $hint Short content preview (~80 chars) of the duplicated code
     */
    public function __construct(
        array $locations,
        public int $lines,
        public int $tokens,
        public string $contentHash,
        public ?string $hint = null,
    ) {
        if (\count($locations) < 2) {
            throw new InvalidArgumentException(
                \sprintf('DuplicateBlock requires at least 2 locations, got %d', \count($locations)),
            );
        }

        if (preg_match('/^[a-f0-9]{64}$/', $contentHash) !== 1) {
            throw new InvalidArgumentException('DuplicateBlock contentHash must be a full lowercase SHA-256 digest');
        }

        // Sort locations deterministically so primaryLocation() is stable
        // regardless of file discovery order
        usort($locations, static fn(DuplicateLocation $a, DuplicateLocation $b) => ($a->pathString() <=> $b->pathString()) !== 0 ? ($a->pathString() <=> $b->pathString()) : ($a->startLine <=> $b->startLine));
        $this->locations = $locations;
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
