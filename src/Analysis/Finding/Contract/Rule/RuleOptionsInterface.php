<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\Severity;

interface RuleOptionsInterface
{
    /**
     * Creates options from configuration array.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self;

    /**
     * Returns whether the rule is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Returns severity for the given metric value, or null if within acceptable range.
     */
    public function getSeverity(int|float $value): ?Severity;

    /**
     * Declares which option keys `fromArray()` answers for at this rule's own
     * depth, and which of them the class refuses or accepts in its own words.
     *
     * Static because this is class-level metadata: the reader consults it
     * before any Options instance exists. It is the single statement of the
     * key set — constructor parameters are no longer read as a second one.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet;
}
