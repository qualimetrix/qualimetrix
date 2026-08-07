<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * What {@see BaselineMigrator::migrate()} produces: the baseline `migrate`
 * writes, plus the report explaining it against the v5 file it replaces.
 *
 * {@see $baseline} is exactly the fresh capture — nothing from the v5 file
 * is merged into it (§7 of the baseline-ceiling plan: "nothing is carried
 * across structurally"). Keeping the two in one VO is what lets a caller
 * write the baseline and print the report from a single return value
 * instead of two calls that could, in principle, disagree about which run
 * they describe.
 */
final readonly class BaselineMigratorResult
{
    public function __construct(
        public Baseline $baseline,
        public MigrationReport $report,
    ) {}
}
