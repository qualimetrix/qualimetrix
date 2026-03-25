<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Violation\Severity;

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
}
