<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

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
        /**
         * Whether this token lies inside a constant declaration (`const`)
         * or a static/instance property's array-literal initializer.
         *
         * Set by {@see DataDeclarationTagger}. Used by {@see DuplicationDetector}
         * to suppress duplication findings entirely contained within data-table
         * declarations, where "extract a shared method" is not actionable advice.
         */
        public bool $isData = false,
    ) {}
}
