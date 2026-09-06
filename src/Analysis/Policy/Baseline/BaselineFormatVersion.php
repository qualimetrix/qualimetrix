<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * The version number of the baseline file format this build reads and writes
 * (ADR 0017).
 *
 * A property of the *format*, not of any loaded snapshot: {@see V5BaselineReader}
 * and {@see BaselineChannelRenamer} both need to know which version is current
 * without ever building a {@see Baseline}, one from a historical envelope it
 * refuses to convert, the other from a decoded document it renames in place.
 * Reading it off {@see Baseline} would have coupled both to a type whose only
 * relevant fact is this one constant.
 */
final class BaselineFormatVersion
{
    public const int CURRENT = 13;

    private function __construct() {}
}
