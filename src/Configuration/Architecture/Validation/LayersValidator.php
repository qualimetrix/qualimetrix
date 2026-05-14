<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use InvalidArgumentException;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;

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

        $allowedEntryKeys = ['name', 'patterns'];
        $unknown = array_diff(array_keys($entry), $allowedEntryKeys);
        if ($unknown !== []) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d]: unknown key(s) %s. Allowed keys: %s.',
                    $index,
                    implode(', ', array_map(static fn($k): string => '"' . (string) $k . '"', $unknown)),
                    implode(', ', array_map(static fn(string $k): string => '"' . $k . '"', $allowedEntryKeys)),
                ),
            );
        }

        if (!\array_key_exists('name', $entry) || !\is_string($entry['name']) || $entry['name'] === '') {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers[%d]: missing or empty "name" (must be a non-empty string).', $index),
            );
        }
        $name = $entry['name'];

        if (isset($seenNames[$name])) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d]: duplicate layer name "%s" — each layer must have a unique identifier.',
                    $index,
                    $name,
                ),
            );
        }
        $seenNames[$name] = true;

        $patterns = self::normalizeLayerPatterns($index, $name, $entry['patterns'] ?? null);

        try {
            return new LayerDefinition($name, $patterns);
        } catch (InvalidLayerDefinitionException $e) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers[%d] ("%s"): %s', $index, $name, $e->getMessage()),
                $e,
            );
        }
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
