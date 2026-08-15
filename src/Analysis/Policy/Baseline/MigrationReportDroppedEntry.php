<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * One v5 acceptance the fresh capture no longer backs — a lost pair of
 * `($symbolKey, $rule)`, named in full because ADR 0017 requires the dropped
 * group to be enumerable: each is either debt that
 * got fixed, or a configuration change that stopped producing it, and a
 * count alone cannot tell a user which is which for a given symbol.
 */
final readonly class MigrationReportDroppedEntry
{
    public function __construct(
        public string $symbolKey,
        public string $rule,
    ) {}
}
