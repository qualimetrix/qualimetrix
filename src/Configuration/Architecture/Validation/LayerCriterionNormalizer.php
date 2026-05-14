<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\Layer\MatchMode;

/**
 * Per-criterion shape and semantic validator shared by {@see LayersValidator}
 * (positive criteria) and {@see ExcludeBlockValidator} (exclude criteria).
 *
 * Stateless: a single instance is reused across all entry indexes within
 * one {@code architecture.layers} validation pass. Each helper takes the
 * raw value plus the path-prefixing fields ({@code $index},
 * {@code $layerName}, {@code $kind}) and either returns a normalized list
 * of strings (or {@see MatchMode}) or throws a
 * {@see ConfigLoadException} with the {@code 'architecture'} path.
 *
 * Accepts three input shapes for criterion lists:
 *
 * - {@code null} — empty list (criterion not declared).
 * - bare string — singleton list (YAML scalar shorthand).
 * - sequential array of strings — the list itself.
 *
 * The semantic check (suffix shape, FQN shape, etc.) is supplied by the
 * caller-specific helper ({@see normalizeSuffixList},
 * {@see normalizeFqnList}, {@see normalizePatternList}).
 *
 * @qmx-ignore health.cohesion Stateless utility — the four public
 *                              normalizers are intentionally independent
 *                              entry points that wrap one shared private
 *                              {@code normalizeStringList} workhorse.
 *                              Low cohesion is the natural shape of a
 *                              function bag, not a defect.
 */
final class LayerCriterionNormalizer
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * @return list<string>
     */
    public function normalizePatternList(int $index, string $layerName, mixed $value): array
    {
        return self::normalizeStringList(
            $index,
            $layerName,
            'patterns',
            $value,
            static fn(string $_): ?string => null,
        );
    }

    /**
     * @return list<string>
     */
    public function normalizeSuffixList(int $index, string $layerName, mixed $value): array
    {
        return self::normalizeStringList(
            $index,
            $layerName,
            'suffix',
            $value,
            static function (string $entry): ?string {
                if (str_contains($entry, '\\')) {
                    return 'must be a short class-name suffix (no backslash); got "' . $entry . '". '
                        . 'Use "patterns" for FQN-shaped entries.';
                }

                return null;
            },
        );
    }

    /**
     * @return list<string>
     */
    public function normalizeFqnList(int $index, string $layerName, string $kind, mixed $value): array
    {
        return self::normalizeStringList(
            $index,
            $layerName,
            $kind,
            $value,
            static function (string $entry) use ($kind): ?string {
                if (!str_contains($entry, '\\')) {
                    return \sprintf(
                        'must be a fully-qualified class name (containing at least one namespace separator); got "%s". '
                        . 'Short names are not accepted in "%s".',
                        $entry,
                        $kind,
                    );
                }

                return null;
            },
        );
    }

    public function normalizeMatchMode(int $index, string $layerName, mixed $value): MatchMode
    {
        if ($value === null) {
            return MatchMode::Any;
        }

        if (\is_string($value)) {
            $candidate = MatchMode::tryFrom($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $allowed = implode(', ', array_map(
            static fn(MatchMode $mode): string => '"' . $mode->value . '"',
            MatchMode::cases(),
        ));

        throw new ConfigLoadException(
            self::CONFIG_PATH,
            \sprintf(
                'architecture.layers[%d] ("%s"): "match" must be one of %s, got %s.',
                $index,
                $layerName,
                $allowed,
                \is_string($value) ? '"' . $value . '"' : get_debug_type($value),
            ),
        );
    }

    /**
     * @param callable(string): ?string $semanticCheck Returns null on success
     *                                                 or an error fragment
     *                                                 appended to the message.
     *
     * @return list<string>
     */
    private static function normalizeStringList(
        int $index,
        string $layerName,
        string $kind,
        mixed $value,
        callable $semanticCheck,
    ): array {
        if ($value === null) {
            return [];
        }

        $entries = self::coerceToStringList($index, $layerName, $kind, $value);

        $normalized = [];
        foreach ($entries as $entryIndex => $entry) {
            $normalized[] = self::validateListEntry($index, $layerName, $kind, $entryIndex, $entry, $semanticCheck);
        }

        return $normalized;
    }

    /**
     * @return list<mixed>
     */
    private static function coerceToStringList(int $index, string $layerName, string $kind, mixed $value): array
    {
        $entries = \is_string($value) ? [$value] : $value;

        if (!\is_array($entries) || !array_is_list($entries)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" must be a string or a non-empty list of strings, got %s.',
                    $index,
                    $layerName,
                    $kind,
                    get_debug_type($value),
                ),
            );
        }

        if ($entries === []) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" must contain at least one entry; omit the key to leave the criterion undeclared.',
                    $index,
                    $layerName,
                    $kind,
                ),
            );
        }

        return $entries;
    }

    /**
     * @param callable(string): ?string $semanticCheck
     */
    private static function validateListEntry(
        int $index,
        string $layerName,
        string $kind,
        int $entryIndex,
        mixed $entry,
        callable $semanticCheck,
    ): string {
        if (!\is_string($entry) || $entry === '') {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" entry at index %d must be a non-empty string (got %s).',
                    $index,
                    $layerName,
                    $kind,
                    $entryIndex,
                    \is_string($entry) ? "''" : get_debug_type($entry),
                ),
            );
        }

        $semanticError = $semanticCheck($entry);
        if ($semanticError !== null) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" entry at index %d %s',
                    $index,
                    $layerName,
                    $kind,
                    $entryIndex,
                    $semanticError,
                ),
            );
        }

        return $entry;
    }
}
