<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Complexity;

use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for method-level cognitive complexity checks.
 */
final readonly class MethodCognitiveComplexityOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 15,
        public int $error = 30,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $thresholds = ThresholdParser::parse($config, 'warning', 'error', 15, 30);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
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
