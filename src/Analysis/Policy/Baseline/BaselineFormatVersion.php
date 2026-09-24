<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * The version number of the baseline file format this build reads and writes
 * (ADR 0017).
 *
 * A property of the *format*, not of any loaded snapshot:
 * {@see BaselineChannelRenamer} needs to know which version is current without
 * ever building a {@see Baseline}, from a decoded document it renames in
 * place. Reading it off {@see Baseline} would couple it to a type whose only
 * relevant fact is this one constant.
 */
final class BaselineFormatVersion
{
    public const int CURRENT = 13;

    private function __construct() {}
}
