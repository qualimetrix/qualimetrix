<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Duplication;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Support\ThresholdParser;

/**
 * Options for the code duplication rule.
 */
final readonly class CodeDuplicationOptions implements RuleOptionsInterface
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
        $thresholds = ThresholdParser::parse($config, 'warning', 'error', 5, 50);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
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
}
