<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Complexity;

use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for class-level NPath complexity checks (max NPath among methods).
 */
final readonly class ClassNpathComplexityOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = false,
        public int $maxWarning = 500,
        public int $maxError = 1000,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $thresholds = ThresholdParser::parse($config, 'max_warning', 'max_error', 500, 1000, legacyWarningKeys: ['maxWarning'], legacyErrorKeys: ['maxError']);

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            maxWarning: (int) $thresholds['warning'],
            maxError: (int) $thresholds['error'],
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->maxError) {
            return Severity::Error;
        }

        if ($value >= $this->maxWarning) {
            return Severity::Warning;
        }

        return null;
    }
}
