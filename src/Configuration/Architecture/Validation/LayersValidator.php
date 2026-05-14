<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use InvalidArgumentException;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Architecture\Layer\MatchMode;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;

/**
 * Parses and validates the {@code architecture.layers} sub-tree.
 *
 * Accepts the long-form ordered list with five criterion kinds (Phase 2
 * direction 1):
 *
 * ```yaml
 * layers:
 *   - name: repository
 *     patterns: ['App\Repository\**']
 *     suffix: 'Repository'
 *     implements: 'Doctrine\Persistence\ObjectRepository'
 *     match: any
 * ```
 *
 * Produces a typed {@see LayerRegistry} preserving declaration order. Rejects
 * duplicate patterns across layers — under declaration-order matching the
 * second occurrence is unreachable and always a configuration mistake.
 *
 * All errors surface as {@see ConfigLoadException} with the logical path
 * {@code 'architecture'}.
 */
final class LayersValidator
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * Keys still reserved for upcoming Phase 2 features. Step B opens
     * {@code suffix}, {@code attributes}, {@code implements} and
     * {@code extends}; {@code exclude} stays reserved until Step F (direction
     * 3) ships.
     */
    private const array RESERVED_FUTURE_KEYS = ['exclude'];

    private const array ALLOWED_ENTRY_KEYS = [
        'name',
        'patterns',
        'suffix',
        'attributes',
        'implements',
        'extends',
        'match',
    ];

    /**
     * Parses the raw `layers` value into a {@see LayerRegistry}.
     */
    public function validate(mixed $layersRaw): LayerRegistry
    {
        $definitions = $this->buildLayerDefinitions($layersRaw);
        self::rejectDuplicatePatterns($definitions);

        return $this->buildRegistry($definitions);
    }

    /**
     * @return list<LayerDefinition>
     */
    private function buildLayerDefinitions(mixed $layersRaw): array
    {
        if ($layersRaw === [] || $layersRaw === null) {
            return [];
        }

        if (!\is_array($layersRaw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture.layers: must be an ordered list of layer entries, got ' . get_debug_type($layersRaw) . '.',
            );
        }

        if (!array_is_list($layersRaw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture.layers: must be an ordered list of layer entries (each entry an object with "name" and at least one criterion key), not a map. '
                . 'See ADR 0006 for the schema change rationale.',
            );
        }

        $definitions = [];
        $seenNames = [];
        foreach ($layersRaw as $index => $entry) {
            $definitions[] = self::buildSingleLayerDefinition($index, $entry, $seenNames);
        }

        return $definitions;
    }

    /**
     * @param array<string, true> $seenNames
     *
     * @param-out array<string, true> $seenNames
     */
    private static function buildSingleLayerDefinition(int $index, mixed $entry, array &$seenNames): LayerDefinition
    {
        $entry = self::ensureEntryIsAssociativeArray($index, $entry);
        self::rejectUnknownKeys($index, $entry);

        $name = self::extractValidName($index, $entry);
        self::rejectDuplicateName($index, $name, $seenNames);
        $seenNames[$name] = true;

        $criteria = self::normalizeCriteria($index, $name, $entry);
        $mode = self::normalizeMatchMode($index, $name, $entry['match'] ?? null);

        return self::buildMembershipDefinition($index, $name, $criteria, $mode);
    }

    /**
     * Collects the five criterion lists from a single layer-entry map.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>}
     */
    private static function normalizeCriteria(int $index, string $name, array $entry): array
    {
        return [
            'patterns' => self::normalizePatternList($index, $name, $entry['patterns'] ?? null),
            'suffix' => self::normalizeSuffixList($index, $name, $entry['suffix'] ?? null),
            'attributes' => self::normalizeFqnList($index, $name, 'attributes', $entry['attributes'] ?? null),
            'implements' => self::normalizeFqnList($index, $name, 'implements', $entry['implements'] ?? null),
            'extends' => self::normalizeFqnList($index, $name, 'extends', $entry['extends'] ?? null),
        ];
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function buildMembershipDefinition(int $index, string $name, array $criteria, MatchMode $mode): LayerDefinition
    {
        if (array_filter($criteria) === []) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): must declare at least one of "patterns", "suffix", "attributes", "implements" or "extends".',
                    $index,
                    $name,
                ),
            );
        }

        try {
            return new LayerDefinition(
                $name,
                new MembershipSpec(
                    patterns: $criteria['patterns'],
                    suffix: $criteria['suffix'],
                    attributes: $criteria['attributes'],
                    implements: $criteria['implements'],
                    extends: $criteria['extends'],
                    mode: $mode,
                ),
            );
        } catch (InvalidLayerDefinitionException | InvalidArgumentException $e) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers[%d] ("%s"): %s', $index, $name, $e->getMessage()),
                $e,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function ensureEntryIsAssociativeArray(int $index, mixed $entry): array
    {
        if (!\is_array($entry) || array_is_list($entry)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d]: each entry must be a map with "name" and at least one criterion key, got %s.',
                    $index,
                    get_debug_type($entry),
                ),
            );
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function rejectUnknownKeys(int $index, array $entry): void
    {
        $unknown = array_diff(array_keys($entry), self::ALLOWED_ENTRY_KEYS);
        if ($unknown === []) {
            return;
        }

        $message = \sprintf(
            'architecture.layers[%d]: unknown key(s) %s. Allowed keys: %s.',
            $index,
            self::quoteList($unknown),
            self::quoteList(self::ALLOWED_ENTRY_KEYS),
        );

        $reservedSeen = array_values(array_intersect(self::RESERVED_FUTURE_KEYS, $unknown));
        if ($reservedSeen !== []) {
            $message .= \sprintf(
                ' Key(s) %s are reserved for an upcoming Phase 2 feature and not yet supported in this version.',
                self::quoteList($reservedSeen),
            );
        }

        throw new ConfigLoadException(self::CONFIG_PATH, $message);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function extractValidName(int $index, array $entry): string
    {
        if (!\array_key_exists('name', $entry) || !\is_string($entry['name']) || $entry['name'] === '') {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers[%d]: missing or empty "name" (must be a non-empty string).', $index),
            );
        }

        return $entry['name'];
    }

    /**
     * @param array<string, true> $seenNames
     */
    private static function rejectDuplicateName(int $index, string $name, array $seenNames): void
    {
        if (!isset($seenNames[$name])) {
            return;
        }

        throw new ConfigLoadException(
            self::CONFIG_PATH,
            \sprintf(
                'architecture.layers[%d]: duplicate layer name "%s" — each layer must have a unique identifier.',
                $index,
                $name,
            ),
        );
    }

    /**
     * @param iterable<int|string> $items
     */
    private static function quoteList(iterable $items): string
    {
        $quoted = [];
        foreach ($items as $item) {
            $quoted[] = '"' . (string) $item . '"';
        }

        return implode(', ', $quoted);
    }

    private static function normalizeMatchMode(int $index, string $layerName, mixed $value): MatchMode
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
     * @return list<string>
     */
    private static function normalizePatternList(int $index, string $layerName, mixed $value): array
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
    private static function normalizeSuffixList(int $index, string $layerName, mixed $value): array
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
    private static function normalizeFqnList(int $index, string $layerName, string $kind, mixed $value): array
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
     * Shared list-validation helper. Accepts:
     *
     * - {@code null} → empty list (criterion not declared).
     * - bare string → singleton list (YAML scalar shorthand).
     * - sequential array of strings → the list itself.
     *
     * Each entry is type-checked, non-empty-checked, and passed to the
     * caller-supplied semantic validator (suffix/FQN/etc.).
     *
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
     * Coerces a raw YAML scalar / list into a non-empty sequential list of
     * strings, rejecting anything that doesn't fit the contract.
     *
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

    /**
     * Rejects duplicate patterns across different layers. Under declaration-
     * order semantics any class matching the duplicate would always belong to
     * the earlier layer — the second occurrence is unreachable and is always
     * a configuration mistake.
     *
     * Same-pattern entries within ONE layer are not duplicates (the layer
     * itself can list whatever it wants), so the check is cross-layer only.
     *
     * Only the {@code patterns} criterion is duplicate-checked: suffix /
     * attributes / implements / extends entries can legitimately overlap
     * across layers because their match semantics are richer than a literal
     * FQN prefix (a suffix-only class might match multiple suffix layers; the
     * declaration-order rule already chooses the assignment unambiguously).
     *
     * @param list<LayerDefinition> $definitions
     */
    private static function rejectDuplicatePatterns(array $definitions): void
    {
        $owners = [];
        foreach ($definitions as $definition) {
            $seenInThisLayer = [];
            foreach ($definition->patterns() as $pattern) {
                $normalized = rtrim($pattern, '\\');
                if (isset($seenInThisLayer[$normalized])) {
                    continue;
                }
                $seenInThisLayer[$normalized] = true;

                if (isset($owners[$normalized]) && $owners[$normalized] !== $definition->name()) {
                    throw new ConfigLoadException(
                        self::CONFIG_PATH,
                        \sprintf(
                            'architecture.layers: pattern "%s" declared in both "%s" and "%s". Under declaration-order matching the second occurrence is unreachable; remove or refine one of them.',
                            $normalized,
                            $owners[$normalized],
                            $definition->name(),
                        ),
                    );
                }
                $owners[$normalized] = $definition->name();
            }
        }
    }

    /**
     * @param list<LayerDefinition> $definitions
     */
    private function buildRegistry(array $definitions): LayerRegistry
    {
        try {
            return new LayerRegistry($definitions);
        } catch (InvalidArgumentException $e) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers: %s', $e->getMessage()),
                $e,
            );
        }
    }
}
