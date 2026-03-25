<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Support;

use InvalidArgumentException;

/**
 * Parses threshold configuration for rules with dual warning/error thresholds.
 *
 * Supports two configuration styles:
 * - Simple: `threshold: X` — sets both warning and error to X (all violations are errors)
 * - Graduated: `warning: X, error: Y` — separate thresholds for different severity levels
 *
 * Mixing `threshold` with `warning`/`error` is a configuration error.
 */
final class ThresholdParser
{
    /**
     * Parses threshold configuration and returns [warning, error] values.
     *
     * @param array<string, mixed> $config Raw configuration array
     * @param string $warningKey Config key for warning threshold (e.g. 'warning', 'max_distance_warning')
     * @param string $errorKey Config key for error threshold (e.g. 'error', 'max_distance_error')
     * @param int|float $defaultWarning Default warning value if not configured
     * @param int|float $defaultError Default error value if not configured
     * @param string $thresholdKey Config key for unified threshold (default: 'threshold')
     * @param list<string> $legacyWarningKeys Additional legacy keys to check for warning (e.g. 'warningThreshold')
     * @param list<string> $legacyErrorKeys Additional legacy keys to check for error (e.g. 'errorThreshold')
     *
     * @throws InvalidArgumentException If threshold is mixed with warning/error keys
     *
     * @return array{warning: int|float, error: int|float}
     */
    public static function parse(
        array $config,
        string $warningKey,
        string $errorKey,
        int|float $defaultWarning,
        int|float $defaultError,
        string $thresholdKey = 'threshold',
        array $legacyWarningKeys = [],
        array $legacyErrorKeys = [],
    ): array {
        $hasThreshold = \array_key_exists($thresholdKey, $config);
        $hasWarning = \array_key_exists($warningKey, $config);
        $hasError = \array_key_exists($errorKey, $config);

        // Check legacy keys for conflict detection
        $hasLegacyWarning = false;
        foreach ($legacyWarningKeys as $legacyKey) {
            if (\array_key_exists($legacyKey, $config)) {
                $hasLegacyWarning = true;
                break;
            }
        }
        $hasLegacyError = false;
        foreach ($legacyErrorKeys as $legacyKey) {
            if (\array_key_exists($legacyKey, $config)) {
                $hasLegacyError = true;
                break;
            }
        }

        if ($hasThreshold && ($hasWarning || $hasError || $hasLegacyWarning || $hasLegacyError)) {
            throw new InvalidArgumentException(
                \sprintf(
                    'Cannot mix "%s" with "%s"/"%s". Use either "%s" alone (simple mode) or "%s"/"%s" (graduated mode).',
                    $thresholdKey,
                    $warningKey,
                    $errorKey,
                    $thresholdKey,
                    $warningKey,
                    $errorKey,
                ),
            );
        }

        if ($hasThreshold) {
            $value = $config[$thresholdKey];

            // Treat null as "not set" — fall back to defaults
            if ($value === null) {
                return ['warning' => $defaultWarning, 'error' => $defaultError];
            }

            return ['warning' => $value, 'error' => $value];
        }

        // Check legacy keys if standard keys are not present
        $warningValue = $config[$warningKey] ?? null;
        if ($warningValue === null) {
            foreach ($legacyWarningKeys as $legacyKey) {
                if (\array_key_exists($legacyKey, $config) && $config[$legacyKey] !== null) {
                    $warningValue = $config[$legacyKey];
                    break;
                }
            }
        }

        $errorValue = $config[$errorKey] ?? null;
        if ($errorValue === null) {
            foreach ($legacyErrorKeys as $legacyKey) {
                if (\array_key_exists($legacyKey, $config) && $config[$legacyKey] !== null) {
                    $errorValue = $config[$legacyKey];
                    break;
                }
            }
        }

        return [
            'warning' => $warningValue ?? $defaultWarning,
            'error' => $errorValue ?? $defaultError,
        ];
    }
}
