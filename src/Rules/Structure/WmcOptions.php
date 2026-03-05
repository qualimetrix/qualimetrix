<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for WmcRule.
 *
 * WMC (Weighted Methods per Class) - sum of cyclomatic complexities of all methods.
 * Thresholds based on Chidamber & Kemerer research and industry practice:
 * - WMC <= 50: well-maintained class (no violation)
 * - WMC 51-80: moderate complexity, needs attention (warning)
 * - WMC > 80: complex class, requires refactoring (error)
 *
 * @see https://pdepend.org/documentation/software-metrics/weighted-method-count.html
 */
final readonly class WmcOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 50,
        public int $error = 80,
        public bool $excludeDataClasses = false,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) ($config['warning'] ?? 50),
            error: (int) ($config['error'] ?? 80),
            excludeDataClasses: (bool) ($config['exclude_data_classes'] ?? $config['excludeDataClasses'] ?? false),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get severity for a given WMC value.
     *
     * Higher WMC = more complex class.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        if ($value > $this->error) {
            return Severity::Error;
        }

        if ($value > $this->warning) {
            return Severity::Warning;
        }

        return null;
    }
}
