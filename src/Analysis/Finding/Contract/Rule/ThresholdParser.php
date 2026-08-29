<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;

/**
 * Parses threshold configuration for rules with dual warning/error thresholds.
 *
 * Supports two configuration styles:
 * - Simple: `threshold: X` — sets both warning and error to X (all findings are errors)
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
     * {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory} and
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
        $candidates = self::candidateKeys($warningKey, $errorKey, $thresholdKey, $legacyKeys);

        // Threshold resolution is *presence*-based: the first candidate key
        // present in the config wins, even when its value is null.
        $thresholdSourceKey = self::firstPresentKey($config, $candidates['threshold']);

        if ($thresholdSourceKey === null) {
            // Graduated mode. Unlike the threshold lookup, warning/error
            // resolution is *value*-based: null candidates are skipped.
            return [
                'warning' => self::firstNonNullValue($config, $candidates['warning']) ?? $defaultWarning,
                'error' => self::firstNonNullValue($config, $candidates['error']) ?? $defaultError,
            ];
        }

        if (self::hasAnyKey($config, $candidates['warning']) || self::hasAnyKey($config, $candidates['error'])) {
            throw new InvalidArgumentException(
                self::mixedModesMessage($warningKey, $errorKey, $thresholdKey),
            );
        }

        $value = $config[$thresholdSourceKey];

        // Treat null as "not set" — fall back to defaults
        return $value === null
            ? ['warning' => $defaultWarning, 'error' => $defaultError]
            : ['warning' => $value, 'error' => $value];
    }

    /**
     * Builds the ordered lookup list for each threshold slot: the primary key
     * first, then its legacy aliases in declaration order.
     *
     * @param array{warning?: list<string>, error?: list<string>, threshold?: list<string>} $legacyKeys
     *
     * @return array{warning: list<string>, error: list<string>, threshold: list<string>}
     */
    private static function candidateKeys(
        string $warningKey,
        string $errorKey,
        string $thresholdKey,
        array $legacyKeys,
    ): array {
        return [
            'warning' => [$warningKey, ...($legacyKeys['warning'] ?? [])],
            'error' => [$errorKey, ...($legacyKeys['error'] ?? [])],
            'threshold' => [$thresholdKey, ...($legacyKeys['threshold'] ?? [])],
        ];
    }

    /**
     * Returns the first candidate key present in the config, or null if none is.
     *
     * Presence is decided by `array_key_exists()`, so an explicitly null value
     * still counts as "the user set this key".
     *
     * @param array<string, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function firstPresentKey(array $config, array $candidateKeys): ?string
    {
        foreach ($candidateKeys as $key) {
            if (\array_key_exists($key, $config)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function hasAnyKey(array $config, array $candidateKeys): bool
    {
        return self::firstPresentKey($config, $candidateKeys) !== null;
    }

    /**
     * Returns the value of the first candidate key configured with a non-null
     * value, or null when every candidate is absent or explicitly null.
     *
     * @param array<string, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function firstNonNullValue(array $config, array $candidateKeys): mixed
    {
        foreach ($candidateKeys as $key) {
            $value = $config[$key] ?? null;

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function mixedModesMessage(string $warningKey, string $errorKey, string $thresholdKey): string
    {
        return \sprintf(
            'Cannot mix "%s" with "%s"/"%s". Use either "%s" alone (simple mode) or "%s"/"%s" (graduated mode).',
            $thresholdKey,
            $warningKey,
            $errorKey,
            $thresholdKey,
            $warningKey,
            $errorKey,
        );
    }
}
