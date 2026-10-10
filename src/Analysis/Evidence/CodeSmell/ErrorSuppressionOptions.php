<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for the error-suppression rule.
 *
 * Allows whitelisting specific functions where @ usage is acceptable
 * (e.g., I/O functions that return false + emit a warning).
 */
final readonly class ErrorSuppressionOptions implements RuleOptionsInterface, EntryFilteringOptionsInterface
{
    /**
     * @param list<string> $allowedFunctions Lowercase function names where @ is allowed
     */
    public function __construct(
        public bool $enabled = true,
        public array $allowedFunctions = [],
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            enabled: $config->boolean('enabled', true),
            allowedFunctions: array_map(strtolower(...), $config->strings('allowed-functions', [])),
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

    public function isFunctionAllowed(string $funcName): bool
    {
        return $this->allowedFunctions !== []
            && \in_array(strtolower($funcName), $this->allowedFunctions, true);
    }

    public function isExtraAllowed(string $extra): bool
    {
        return $this->isFunctionAllowed($extra);
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'allowed-functions' => RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text()))->orNull(),
        ]);
    }
}
