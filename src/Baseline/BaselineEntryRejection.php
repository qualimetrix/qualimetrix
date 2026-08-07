<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use RuntimeException;

/**
 * Internal signal that one entry cannot be applied, carrying the reason a
 * report will show.
 *
 * It never leaves {@see BaselineEntryParser}: the parser catches it and
 * returns an {@see InertBaselineEntry}. It exists so that the dozen
 * independent checks ADR 0017 requires read as a straight sequence instead of a
 * nest of early returns, each of which would have to re-assemble the same
 * inert entry.
 */
final class BaselineEntryRejection extends RuntimeException
{
    public function __construct(
        public readonly InertEntryReason $reason,
        string $detail,
    ) {
        parent::__construct($detail);
    }
}
