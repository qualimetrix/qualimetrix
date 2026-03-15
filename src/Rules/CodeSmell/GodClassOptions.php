<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for GodClassRule.
 *
 * God Class detection uses Lanza & Marinescu criteria:
 * - WMC >= 47 (high complexity)
 * - LCOM4 >= 3 (low cohesion)
 * - TCC < 0.33 (low tight class cohesion, inverted)
 * - classLoc >= 300 (large size)
 *
 * A class is a God Class when it matches minCriteria out of evaluable criteria.
 */
final readonly class GodClassOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $wmcThreshold = 47,
        public int $lcomThreshold = 3,
        public float $tccThreshold = 0.33,
        public int $classLocThreshold = 300,
        public int $minCriteria = 3,
        public int $minMethods = 3,
        public bool $excludeReadonly = true,
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
            wmcThreshold: (int) ($config['wmc_threshold'] ?? $config['wmcThreshold'] ?? 47),
            lcomThreshold: (int) ($config['lcom_threshold'] ?? $config['lcomThreshold'] ?? 3),
            tccThreshold: (float) ($config['tcc_threshold'] ?? $config['tccThreshold'] ?? 0.33),
            classLocThreshold: (int) ($config['class_loc_threshold'] ?? $config['classLocThreshold'] ?? 300),
            minCriteria: (int) ($config['min_criteria'] ?? $config['minCriteria'] ?? 3),
            minMethods: (int) ($config['min_methods'] ?? $config['minMethods'] ?? 3),
            excludeReadonly: (bool) ($config['exclude_readonly'] ?? $config['excludeReadonly'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Severity is determined by the rule's analyze() logic, not here.
     * This method satisfies the interface contract.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        // Severity is determined inline in analyze() based on matchedCount vs evaluableCount
        return $value > 0 ? Severity::Warning : null;
    }
}
