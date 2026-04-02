<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Coupling;

use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for class-level instability checks.
 *
 * Instability range: [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 */
final readonly class ClassInstabilityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $maxWarning = 0.8,
        public float $maxError = 0.95,
        public bool $skipLeaf = true,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // If config is empty, use defaults (all enabled)
        if ($config === []) {
            return new self();
        }

        $thresholds = ThresholdParser::parse($config, 'max_warning', 'max_error', 0.8, 0.95, legacyWarningKeys: ['maxWarning'], legacyErrorKeys: ['maxError']);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxWarning: (float) $thresholds['warning'],
            maxError: (float) $thresholds['error'],
            skipLeaf: (bool) ($config['skip_leaf'] ?? $config['skipLeaf'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $instability = (float) $value;

        if ($instability >= $this->maxError) {
            return Severity::Error;
        }

        if ($instability >= $this->maxWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            maxWarning: $warning !== null ? (float) $warning : $this->maxWarning,
            maxError: $error !== null ? (float) $error : $this->maxError,
            skipLeaf: $this->skipLeaf,
        );
    }
}
