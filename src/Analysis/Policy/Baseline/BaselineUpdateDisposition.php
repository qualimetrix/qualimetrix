<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * What `baseline:update` did to one entry (ADR 0017).
 */
enum BaselineUpdateDisposition: string
{
    /** The entry's magnitudes and/or count were replaced by the measured group. */
    case Updated = 'updated';

    /**
     * The entry's identity was measured, but the measured group could not
     * replace it — see {@see BaselineUpdateRefusalReason} for why. The entry
     * is written back exactly as it was.
     */
    case Refused = 'refused';

    /**
     * The entry's identity did not appear in the measured set. Left
     * untouched: a vanished group is `baseline:cleanup`'s business, not a
     * reason for `update` to rewrite an entry to nothing (ADR 0017).
     */
    case Skipped = 'skipped';
}
