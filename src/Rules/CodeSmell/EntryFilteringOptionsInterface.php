<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

/**
 * Implemented by code smell options that whitelist individual occurrences
 * based on the entry's `extra` value (e.g. parameter name, function name).
 *
 * Allows AbstractCodeSmellRule to filter entries generically without each
 * rule having to override shouldIncludeEntry().
 */
interface EntryFilteringOptionsInterface
{
    /**
     * Returns true when the given extra value is whitelisted and the
     * corresponding entry must be excluded from violations.
     */
    public function isExtraAllowed(string $extra): bool;
}
