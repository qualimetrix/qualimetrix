<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Coupling;

use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for namespace-level instability checks.
 *
 * Instability range: [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 */
final readonly class NamespaceInstabilityOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $maxWarning = 0.8,
        public float $maxError = 0.95,
        public int $minClassCount = 3,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self();
        }

        $thresholds = ThresholdParser::parse($config, 'max_warning', 'max_error', 0.8, 0.95, legacyWarningKeys: ['maxWarning'], legacyErrorKeys: ['maxError']);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxWarning: (float) $thresholds['warning'],
            maxError: (float) $thresholds['error'],
            minClassCount: (int) ($config['min_class_count'] ?? $config['minClassCount'] ?? 3),
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
}
