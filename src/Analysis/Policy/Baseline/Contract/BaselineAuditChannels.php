<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract;

/** Audit findings describe the baseline itself and cannot become accepted entries. */
final class BaselineAuditChannels
{
    public const string UNUSED_ENTRY = 'baseline.unused-entry';

    public const array PROJECT_SCOPED_CHANNELS = [self::UNUSED_ENTRY];

    private function __construct() {}
}
