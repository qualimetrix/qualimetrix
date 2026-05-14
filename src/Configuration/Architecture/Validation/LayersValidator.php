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
 * Accepts the long-form ordered list:
 *
 * ```yaml
 * layers:
 *   - name: controller
 *     patterns: ['App\Controller\**']
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
     * Keys reserved for upcoming Phase 2 features (Step B, Step F). They are
     * rejected today with a friendlier message so the user understands the
     * difference between "always invalid" and "planned, not yet shipped".
     * Once Step B/F open the schema, these keys move out of the reserved
     * list into the allowed list.
     */
    private const array RESERVED_FUTURE_KEYS = ['suffix', 'attributes', 'implements', 'extends', 'exclude'];

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
                'architecture.layers: must be an ordered list of layer entries (each entry an object with "name" and "patterns" keys), not a map. '
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

        $patterns = self::normalizeLayerPatterns($index, $name, $entry['patterns'] ?? null);
        $mode = self::normalizeMatchMode($index, $name, $entry['match'] ?? null);

        try {
            return new LayerDefinition($name, new MembershipSpec($patterns, $mode));
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
                    'architecture.layers[%d]: each entry must be a map with "name" and "patterns" keys, got %s.',
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
        $allowedEntryKeys = ['name', 'patterns', 'match'];
        $unknown = array_diff(array_keys($entry), $allowedEntryKeys);
        if ($unknown === []) {
            return;
        }

        $message = \sprintf(
            'architecture.layers[%d]: unknown key(s) %s. Allowed keys: %s.',
            $index,
            self::quoteList($unknown),
            self::quoteList($allowedEntryKeys),
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
    private static function normalizeLayerPatterns(int $index, string $layerName, mixed $value): array
    {
        if ($value === null) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers[%d] ("%s"): missing "patterns" key (must be a non-empty list of strings).', $index, $layerName),
            );
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "patterns" must be a non-empty list of strings, got %s.',
                    $index,
                    $layerName,
                    get_debug_type($value),
                ),
            );
        }

        if ($value === []) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers[%d] ("%s"): "patterns" must contain at least one entry.', $index, $layerName),
            );
        }

        $patterns = [];
        foreach ($value as $patternIndex => $pattern) {
            if (!\is_string($pattern) || $pattern === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf(
                        'architecture.layers[%d] ("%s"): "patterns" entry at index %d must be a non-empty string (got %s).',
                        $index,
                        $layerName,
                        $patternIndex,
                        \is_string($pattern) ? "''" : get_debug_type($pattern),
                    ),
                );
            }
            $patterns[] = $pattern;
        }

        return $patterns;
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
