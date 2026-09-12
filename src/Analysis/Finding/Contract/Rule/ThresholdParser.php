<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * Parses threshold configuration for rules with dual warning/error thresholds.
 *
 * Supports two configuration styles:
 * - Simple: `threshold: X` — sets both warning and error to X (all findings are errors)
 * - Graduated: `warning: X, error: Y` — separate thresholds for different severity levels
 *
 * Mixing `threshold` with `warning`/`error` is a configuration error.
 *
 * ## A key is what it was written WITH, not that it was written
 *
 * Every question here — which mode was chosen, and whether the two modes were
 * mixed — is asked of keys carrying a NON-NULL value. A key written `~` is a
 * key whose value the author left to the default, exactly as the rest of the
 * document reads it, so it selects no mode and mixes with nothing.
 *
 * It used to be asked of key PRESENCE instead, and that answered two different
 * questions with one word. `threshold: ~` beside `warning: 5` was refused as a
 * mix although no second value was written anywhere; the same `~` shadowed a
 * populated alias behind it. Both are gone.
 */
final class ThresholdParser
{
    /**
     * Parses threshold configuration and returns [warning, error] values.
     *
     * `$legacyKeys` lists additional fallback keys per primary key, e.g. the
     * camelCase form of a composite `$warningKey`/`$errorKey`/`$thresholdKey`
     * (`'maxWarning'`, `'voThreshold'`, ...) — needed because every door folds
     * separators away before `fromArray()` runs, so a key written
     * `max_warning` arrives as `maxWarning`.
     *
     * A key read here and nowhere else is invisible to reflection, which is
     * why the class that reads it declares it: see
     * `RuleOptionsInterface::acceptedOptionKeys()`. The bare `$thresholdKey`
     * default is the case that forces the point — no constructor names it, it
     * works, and it is documented on the website.
     *
     * @param array<string, mixed> $config Raw configuration array
     * @param string $warningKey Config key for warning threshold (e.g. 'warning', 'max_distance_warning')
     * @param string $errorKey Config key for error threshold (e.g. 'error', 'max_distance_error')
     * @param int|float $defaultWarning Default warning value if not configured
     * @param int|float $defaultError Default error value if not configured
     * @param string $thresholdKey Config key for unified threshold (default: 'threshold')
     * @param array{warning?: list<string>, error?: list<string>, threshold?: list<string>} $legacyKeys Fallback keys, keyed by which primary key they alias
     *
     * @throws ConfigurationRefusal If threshold is mixed with warning/error keys
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

        $thresholdSourceKey = self::firstWrittenKey($config, $candidates['threshold']);

        if ($thresholdSourceKey === null) {
            // Graduated mode, either because a graduated key carries a value
            // or because nothing here carries one at all.
            return [
                'warning' => self::firstWrittenValue($config, $candidates['warning']) ?? $defaultWarning,
                'error' => self::firstWrittenValue($config, $candidates['error']) ?? $defaultError,
            ];
        }

        if (self::hasAnyWrittenKey($config, $candidates['warning']) || self::hasAnyWrittenKey($config, $candidates['error'])) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([$thresholdSourceKey], $thresholdSourceKey),
                self::mixedModesMessage($warningKey, $errorKey, $thresholdKey),
            );
        }

        $value = $config[$thresholdSourceKey];

        return ['warning' => $value, 'error' => $value];
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
     * Returns the first candidate key written with a value, or null when every
     * candidate is either absent or written `~`.
     *
     * The one traversal behind all three questions this class asks, so "was it
     * written" cannot come to mean one thing for the mode and another for the
     * mix.
     *
     * @param array<string, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function firstWrittenKey(array $config, array $candidateKeys): ?string
    {
        foreach ($candidateKeys as $key) {
            if (isset($config[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function hasAnyWrittenKey(array $config, array $candidateKeys): bool
    {
        return self::firstWrittenKey($config, $candidateKeys) !== null;
    }

    /**
     * The value behind {@see firstWrittenKey()}, or null when there is none.
     *
     * @param array<string, mixed> $config
     * @param list<string> $candidateKeys
     */
    private static function firstWrittenValue(array $config, array $candidateKeys): mixed
    {
        $key = self::firstWrittenKey($config, $candidateKeys);

        return $key === null ? null : $config[$key];
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
