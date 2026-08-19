<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerLifecycle;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;

/**
 * Per-criterion shape and semantic validator shared by {@see LayersValidator}
 * (positive criteria) and {@see ExcludeBlockValidator} (exclude criteria).
 *
 * Stateless: a single instance is reused across all entry indexes within
 * one {@code architecture.layers} validation pass. Each helper takes the
 * raw value plus the path-prefixing fields ({@code $index},
 * {@code $layerName}, {@code $kind}) and either returns a normalized list
 * of strings (or {@see MatchMode}) or throws a
 * {@see ArchitectureConfigurationException} with the {@code 'architecture'} path.
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
 * @qmx-ignore health.cohesion -- Stateless validation operations share no instance fields by design.
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

    /**
     * Reads the optional {@code pending:} flag — the author's declaration that
     * the layer describes code not written yet, so
     * {@code architecture.unreachable-layer} must not report it.
     *
     * Only a real boolean is accepted. YAML already turns {@code yes}/{@code on}
     * into `true`, so anything arriving here as a string is a value the author
     * believed in and the parser did not — reading it as truthy is how a safety
     * net gets switched off by accident.
     *
     * A template entry is rejected rather than ignored: it expands per observed
     * tuple, so its instances have matched something by construction and the
     * flag could never do anything. A template that produced nothing is
     * {@code architecture.empty-template}, a different channel the flag
     * deliberately does not reach.
     *
     * @param array<string, mixed> $entry The whole layer entry: the key is
     *                                    optional, and reading it here keeps
     *                                    its absence one decision rather than
     *                                    two.
     */
    public function normalizeLifecycle(int $index, string $layerName, array $entry, bool $isTemplate): LayerLifecycle
    {
        $value = $entry['pending'] ?? null;

        if ($value === null || $value === false) {
            return LayerLifecycle::Active;
        }

        if ($value !== true) {
            throw $this->entryError($index, $layerName, \sprintf(
                '"pending" must be a boolean, got %s.',
                get_debug_type($value),
            ));
        }

        if ($isTemplate) {
            throw $this->entryError(
                $index,
                $layerName,
                '"pending" is not applicable to a template layer — a template expands only from tuples observed '
                . 'in the analysed code, so its instances always match something. A template that expanded to '
                . 'nothing is reported as architecture.empty-template.',
            );
        }

        return LayerLifecycle::Pending;
    }

    /**
     * The `architecture.layers[i] ("name"): ...` prefix both entry-level
     * normalizers report against, so the two cannot drift apart.
     */
    private function entryError(int $index, string $layerName, string $message): ArchitectureConfigurationException
    {
        return new ArchitectureConfigurationException(
            self::CONFIG_PATH,
            \sprintf('architecture.layers[%d] ("%s"): %s', $index, $layerName, $message),
        );
    }

    public function normalizeMatchMode(int $index, string $layerName, mixed $value): MatchMode
    {
        if ($value === null) {
            return MatchMode::Any;
        }

        if (\is_string($value)) {
            // Case-insensitive: `ANY`, `Any`, `any`, `aLL`, etc. all resolve.
            // `MatchMode::tryFrom()` itself is case-sensitive; normalize the
            // user input before delegation so the enum cases stay the single
            // source of truth for the canonical spelling.
            $candidate = MatchMode::tryFrom(strtolower($value));
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $allowed = implode(', ', array_map(
            static fn(MatchMode $mode): string => '"' . $mode->value . '"',
            MatchMode::cases(),
        ));

        throw $this->entryError($index, $layerName, \sprintf(
            '"match" must be one of %s, got %s.',
            $allowed,
            \is_string($value) ? '"' . $value . '"' : get_debug_type($value),
        ));
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

        if (!\is_array($entries)) {
            throw new ArchitectureConfigurationException(
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

        if (!array_is_list($entries)) {
            // Associative map where an ordered list is required — the
            // typical mistake is using YAML mapping syntax ({@code key: val})
            // for what should be a sequence ({@code - val}).
            throw new ArchitectureConfigurationException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" must be a string or a non-empty list of strings, got an associative map (keys: %s). Use sequence syntax (a "-" prefix per entry) or omit the key to leave the criterion undeclared.',
                    $index,
                    $layerName,
                    $kind,
                    self::renderMapKeysForError($entries),
                ),
            );
        }

        if ($entries === []) {
            throw new ArchitectureConfigurationException(
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
     * Renders the keys of an associative map in a stable, bounded form for
     * inclusion in an error message. Caps the list at four keys to keep the
     * error one-line readable when the user paste a large map by accident.
     *
     * @param array<array-key, mixed> $map
     */
    private static function renderMapKeysForError(array $map): string
    {
        $keys = array_keys($map);
        $shown = \array_slice($keys, 0, 4);
        $quoted = array_map(static fn(int|string $k): string => '"' . (string) $k . '"', $shown);
        $tail = \count($keys) > 4 ? ', …' : '';

        return implode(', ', $quoted) . $tail;
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
            throw new ArchitectureConfigurationException(
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
            throw new ArchitectureConfigurationException(
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
