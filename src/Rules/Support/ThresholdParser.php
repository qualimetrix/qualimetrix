<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Support;

use InvalidArgumentException;
use Qualimetrix\Core\Rule\RuleOptionKey;

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
     * `$legacyKeys` lists additional fallback keys per primary key, e.g. the
     * camelCase form of a composite `$warningKey`/`$errorKey`/`$thresholdKey`
     * (`'maxWarning'`, `'voThreshold'`, ...) — needed because
     * {@see \Qualimetrix\Configuration\RuleOptionsFactory} and
     * `RuleOptionsParser` normalize config-file/CLI keys to camelCase before
     * `fromArray()` runs.
     *
     * @param array<string, mixed> $config Raw configuration array
     * @param string $warningKey Config key for warning threshold (e.g. 'warning', 'max_distance_warning')
     * @param string $errorKey Config key for error threshold (e.g. 'error', 'max_distance_error')
     * @param int|float $defaultWarning Default warning value if not configured
     * @param int|float $defaultError Default error value if not configured
     * @param string $thresholdKey Config key for unified threshold (default: 'threshold')
     * @param array{warning?: list<string>, error?: list<string>, threshold?: list<string>} $legacyKeys Fallback keys, keyed by which primary key they alias
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
        string $thresholdKey = RuleOptionKey::THRESHOLD,
        array $legacyKeys = [],
    ): array {
        $legacyWarningKeys = $legacyKeys['warning'] ?? [];
        $legacyErrorKeys = $legacyKeys['error'] ?? [];
        $legacyThresholdKeys = $legacyKeys['threshold'] ?? [];

        $hasThreshold = \array_key_exists($thresholdKey, $config);
        $thresholdSourceKey = $thresholdKey;

        if (!$hasThreshold) {
            foreach ($legacyThresholdKeys as $legacyKey) {
                if (\array_key_exists($legacyKey, $config)) {
                    $hasThreshold = true;
                    $thresholdSourceKey = $legacyKey;
                    break;
                }
            }
        }

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
            $value = $config[$thresholdSourceKey];

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
