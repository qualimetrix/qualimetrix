<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\DocumentReading;

use Qualimetrix\Analysis\Policy\Baseline\Contract\Entry\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\Contract\Entry\InertEntryReason;

/**
 * The version numbers of the baseline file format this build reads and writes
 * (ADR 0017).
 *
 * A property of the *format*, not of any loaded snapshot: a {@see Baseline}
 * is always written as {@see CURRENT}, so it carries no version of its own.
 */
final class BaselineFormatVersion
{
    public const int CURRENT = 15;

    /**
     * The oldest version this build still loads. Every other version below
     * {@see CURRENT} is refused with its own migration instruction.
     */
    public const int OLDEST_READABLE = 14;

    /**
     * The first version whose closure keys name the closure's enclosing
     * declaration (ADR 0138). An older closure key is spelled in the same
     * grammar but meant a file-wide rank, and a file-level closure can hold
     * that spelling today, so such an entry is loaded as
     * {@see InertEntryReason::RetiredClosureIdentity} instead of binding to it.
     */
    public const int ENCLOSING_DECLARATION_CLOSURE_NAMES = 15;

    private function __construct() {}

    public static function isReadable(int $version): bool
    {
        return $version >= self::OLDEST_READABLE && $version <= self::CURRENT;
    }
}
