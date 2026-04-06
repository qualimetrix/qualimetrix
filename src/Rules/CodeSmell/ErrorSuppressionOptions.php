<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Options for the error-suppression rule.
 *
 * Allows whitelisting specific functions where @ usage is acceptable
 * (e.g., I/O functions that return false + emit a warning).
 */
final readonly class ErrorSuppressionOptions implements RuleOptionsInterface
{
    /**
     * @param list<string> $allowedFunctions Lowercase function names where @ is allowed
     */
    public function __construct(
        public bool $enabled = true,
        public array $allowedFunctions = [],
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $raw = $config['allowedFunctions'] ?? $config['allowed_functions'] ?? [];

        $functions = [];
        if (\is_string($raw)) {
            $functions = [strtolower($raw)];
        } elseif (\is_array($raw)) {
            $functions = array_map('strtolower', array_values(array_filter($raw, 'is_string')));
        }

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            allowedFunctions: $functions,
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
}
