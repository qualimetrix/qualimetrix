<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Simple options for code smell rules.
 *
 * Most code smell rules just need enabled/disabled toggle.
 */
final readonly class CodeSmellOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            enabled: $config->boolean('enabled', true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $value > 0 ? Severity::Error : null;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
        ]);
    }
}
