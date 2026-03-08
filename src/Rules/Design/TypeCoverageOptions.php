<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Design;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for TypeCoverageRule.
 *
 * Checks the percentage of typed parameters, return types, and properties.
 * Lower values are worse (inverted thresholds compared to most rules).
 *
 * Default thresholds:
 * - warning: below 80% coverage
 * - error: below 50% coverage
 */
final readonly class TypeCoverageOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $paramWarning = 80.0,
        public float $paramError = 50.0,
        public float $returnWarning = 80.0,
        public float $returnError = 50.0,
        public float $propertyWarning = 80.0,
        public float $propertyError = 50.0,
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
            paramWarning: (float) ($config['param_warning'] ?? 80.0),
            paramError: (float) ($config['param_error'] ?? 50.0),
            returnWarning: (float) ($config['return_warning'] ?? 80.0),
            returnError: (float) ($config['return_error'] ?? 50.0),
            propertyWarning: (float) ($config['property_warning'] ?? 80.0),
            propertyError: (float) ($config['property_error'] ?? 50.0),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns null by design — this rule has 3 separate dimensions (param/return/property),
     * each with its own thresholds, so a single getSeverity() is meaningless.
     * The rule uses getParamSeverity(), getReturnSeverity(), getPropertySeverity() instead.
     * This method exists only to satisfy RuleOptionsInterface.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }

    public function getParamSeverity(float $coverage): ?Severity
    {
        if ($coverage < $this->paramError) {
            return Severity::Error;
        }

        if ($coverage < $this->paramWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function getReturnSeverity(float $coverage): ?Severity
    {
        if ($coverage < $this->returnError) {
            return Severity::Error;
        }

        if ($coverage < $this->returnWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function getPropertySeverity(float $coverage): ?Severity
    {
        if ($coverage < $this->propertyError) {
            return Severity::Error;
        }

        if ($coverage < $this->propertyWarning) {
            return Severity::Warning;
        }

        return null;
    }
}
