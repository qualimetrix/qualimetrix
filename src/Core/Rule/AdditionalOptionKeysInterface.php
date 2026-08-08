<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

/**
 * Declares top-level configuration keys accepted by an Options class beyond
 * its constructor parameters and threshold shorthand keys.
 *
 * RuleOptionsFactory validates unknown keys before it creates the Options
 * instance. Options classes use this contract when fromArray() deliberately
 * consumes a top-level key that is neither a constructor field nor a bare
 * threshold shorthand.
 */
interface AdditionalOptionKeysInterface
{
    /**
     * @return list<string> Canonical kebab-case option keys
     */
    public static function getAdditionalOptionKeys(): array;
}
