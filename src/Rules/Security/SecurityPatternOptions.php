<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Security;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Shared options for security pattern rules (SQL injection, XSS, command injection).
 *
 * Note: getSeverity() is required by RuleOptionsInterface but not used by
 * AbstractSecurityPatternRule, which determines severity via its own abstract method.
 */
final readonly class SecurityPatternOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
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

    /**
     * Required by RuleOptionsInterface. Not used by AbstractSecurityPatternRule
     * which determines severity via its own abstract getSeverity() method.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        return $value > 0 ? Severity::Error : null;
    }
}
