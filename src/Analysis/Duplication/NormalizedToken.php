<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Duplication;

/**
 * A normalized token for duplication detection.
 *
 * Token normalization replaces variable names, string literals, and numbers
 * with placeholders so that structurally identical code with different
 * identifiers is treated as a duplicate.
 */
final readonly class NormalizedToken
{
    public function __construct(
        public int $type,
        public string $value,
        public int $line,
    ) {}
}
