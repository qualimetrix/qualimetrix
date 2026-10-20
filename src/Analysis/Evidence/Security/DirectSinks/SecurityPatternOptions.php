<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security\DirectSinks;

use Qualimetrix\Analysis\Finding\Contract\Rule\EnablementOptionsTrait;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Shared options for security pattern rules (SQL injection, XSS, command injection).
 *
 * Note: getSeverity() is required by RuleOptionsInterface but not used by
 * AbstractSecurityPatternRule, which determines severity via its own abstract method.
 */
final readonly class SecurityPatternOptions implements RuleOptionsInterface
{
    use EnablementOptionsTrait;

    public function __construct(
        public bool $enabled = true,
    ) {}

    /**
     * Required by RuleOptionsInterface. Not used by AbstractSecurityPatternRule
     * which determines severity via its own abstract getSeverity() method.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        return $value > 0 ? Severity::Error : null;
    }

}
