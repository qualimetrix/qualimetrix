<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Options for the unused-private rule.
 */
final readonly class UnusedPrivateOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $value > 0 ? Severity::Warning : null;
    }
}
