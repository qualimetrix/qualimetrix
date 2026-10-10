<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for a specific level of a hierarchical rule.
 *
 * Each level (method, class, namespace) can have its own thresholds.
 */
interface LevelOptionsInterface
{
    /**
     * Creates level options from the resolved configuration document.
     */
    public static function fromResolved(ResolvedRuleOptionValues $config): self;

    /**
     * Returns whether this level is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Returns severity for the given metric value, or null if within acceptable range.
     */
    public function getSeverity(int|float $value): ?Severity;

    /**
     * Declares which option keys may be written inside this level's slot.
     *
     * A slot answers for itself: two slots of one rule accept disjoint key
     * sets — `callable` takes `warning`/`error` where `class` takes
     * `max-warning`/`max-error` — so the parent cannot answer for either.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet;
}
