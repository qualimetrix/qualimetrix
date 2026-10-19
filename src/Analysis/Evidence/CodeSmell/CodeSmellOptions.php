<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Rule\EnablementOptionsTrait;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Simple options for code smell rules.
 *
 * Most code smell rules just need enabled/disabled toggle.
 */
final readonly class CodeSmellOptions implements RuleOptionsInterface
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
