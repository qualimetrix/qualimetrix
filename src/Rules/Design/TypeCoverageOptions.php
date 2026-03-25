<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

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

        $paramThresholds = ThresholdParser::parse($config, 'param_warning', 'param_error', 80.0, 50.0, thresholdKey: 'param_threshold', legacyWarningKeys: ['paramWarning'], legacyErrorKeys: ['paramError']);
        $returnThresholds = ThresholdParser::parse($config, 'return_warning', 'return_error', 80.0, 50.0, thresholdKey: 'return_threshold', legacyWarningKeys: ['returnWarning'], legacyErrorKeys: ['returnError']);
        $propertyThresholds = ThresholdParser::parse($config, 'property_warning', 'property_error', 80.0, 50.0, thresholdKey: 'property_threshold', legacyWarningKeys: ['propertyWarning'], legacyErrorKeys: ['propertyError']);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            paramWarning: (float) $paramThresholds['warning'],
            paramError: (float) $paramThresholds['error'],
            returnWarning: (float) $returnThresholds['warning'],
            returnError: (float) $returnThresholds['error'],
            propertyWarning: (float) $propertyThresholds['warning'],
            propertyError: (float) $propertyThresholds['error'],
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
