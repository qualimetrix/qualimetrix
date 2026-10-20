<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use Qualimetrix\Analysis\Finding\Contract\Rule\EnablementOptionsTrait;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for the hardcoded credentials rule.
 */
final readonly class HardcodedCredentialsOptions implements RuleOptionsInterface
{
    use EnablementOptionsTrait;

    public function __construct(
        public bool $enabled = true,
    ) {}

    public function getSeverity(int|float $value): ?Severity
    {
        return $value > 0 ? Severity::Error : null;
    }

}
