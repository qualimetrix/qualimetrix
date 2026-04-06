<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Duplication;

use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for the code duplication rule.
 */
final readonly class CodeDuplicationOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $min_lines = 5,
        public int $min_tokens = 70,
        public int $warning = 5,
        public int $error = 50,
    ) {}

    public static function fromArray(array $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 5, 50);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            min_lines: (int) ($config['min_lines'] ?? $config['minLines'] ?? 5),
            min_tokens: (int) ($config['min_tokens'] ?? $config['minTokens'] ?? 70),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->error) {
            return Severity::Error;
        }

        if ($value >= $this->warning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            min_lines: $this->min_lines,
            min_tokens: $this->min_tokens,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
        );
    }
}
