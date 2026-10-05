<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\EntryBinding;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

final readonly class UnusedEntryOptions implements RuleOptionsInterface
{
    public function __construct(public bool $enabled = true) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self($config->boolean('enabled', true));
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): Severity
    {
        return Severity::Warning;
    }
}
