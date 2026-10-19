<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\EntryBinding;

use Qualimetrix\Analysis\Finding\Contract\Rule\EnablementOptionsTrait;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

final readonly class UnusedEntryOptions implements RuleOptionsInterface
{
    use EnablementOptionsTrait;

    public function __construct(public bool $enabled = true) {}

    public function getSeverity(int|float $value): Severity
    {
        return Severity::Warning;
    }
}
