<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;

/**
 * Converts the raw YAML map under the {@code architecture:} key into a typed
 * {@see ArchitectureFactoryResult} carrying the resolved
 * {@see ArchitectureConfiguration} and any deferred warnings.
 *
 * Schema (declaration-order matching, first match wins):
 *
 * ```yaml
 * architecture:
 *   layers:
 *     - name: controller
 *       patterns: ['App\Controller\**']
 *     - name: repository
 *       patterns: ['App\Repository\**']
 *   allow:
 *     controller: [repository]
 *   coverage: ignore
 * ```
 *
 * `layers` is an **ordered list**; the first layer whose patterns match a class
 * FQN owns that class. There is no specificity scoring and no collision
 * detection — order is the user's tool to express intent. See
 * {@see \Qualimetrix\Core\Architecture\Layer\LayerRegistry} and ADR 0006.
 *
 * Responsibilities:
 * - Structural validation (top-level keys, layer list shape, allow shape, coverage value).
 * - Cross-validation between `layers` and `allow` (every reference in `allow`
 *   must name a declared layer).
 * - Long-form allow-entry normalization (`{target, types}` → bare string;
 *   `types` is reserved for a future filter and triggers a deprecation warning).
 * - Mutual-allow detection (`A → B` AND `B → A`) — surfaced as a warning.
 * - Duplicate-pattern detection across layers (under declaration-order
 *   semantics any class matching the duplicate would always go to the earlier
 *   layer — the second layer is unreachable; reject at load with a clear error).
 *
 * All structural errors surface as {@see ConfigLoadException} with the
 * logical path {@code 'architecture'}.
 *
 * The optional `LoggerInterface` argument is retained for backward
 * compatibility with the configuration pipeline; Step 1 of the follow-up
 * plan removes it in favour of draining
 * {@see ArchitectureFactoryResult::$warnings} via the runtime configurator.
 * For now, when a logger is provided, warnings are also emitted through it
 * (matching the pre-pivot behaviour).
 *
 * @qmx-threshold complexity.wmc warning=85 error=100
 *                Comprehensive structural validation of a free-form YAML map
 *                produces many short, focused validation methods (top-level
 *                shape, layer entries, allow entries, coverage, duplicate
 *                patterns, mutual-allow). WMC stays high because each step is
 *                its own method (preferable to one big validator). All methods
 *                are individually simple; Step 3 of the follow-up plan
 *                decomposes the class into per-concern validators.
 */
final class ArchitectureConfigurationFactory
{
    private const string CONFIG_PATH = 'architecture';

    private const array ALLOWED_TOP_LEVEL_KEYS = ['layers', 'allow', 'coverage'];

    /**
     * Converts the merged YAML map under {@code architecture:} to a typed result.
     *
     * Callers can pass {@code $merged['architecture'] ?? []} directly; both
     * associative and (degenerate) sequential arrays are accepted at the type
     * level and rejected by structural validation below.
     *
     * Unknown top-level keys (typos like {@code layres:} or unrecognized fields
     * like {@code imports:}) trigger a {@see ConfigLoadException} so that user
     * mistakes never silently disable architecture rules.
     *
     * @param array<string, mixed>|array<int, mixed> $raw
     */
    public function fromArray(array $raw, ?LoggerInterface $logger = null): ArchitectureFactoryResult
    {
        $logger ??= new NullLogger();

        if ($raw === []) {
            return new ArchitectureFactoryResult(
                new ArchitectureConfiguration(
                    new LayerRegistry([]),
                    new LayerPolicy([]),
                    CoverageMode::Ignore,
                ),
            );
        }

        $this->validateTopLevelStructure($raw);

        $layersRaw = $raw['layers'] ?? [];
        $allowRaw = $raw['allow'] ?? [];
        $coverageRaw = $raw['coverage'] ?? null;

        $definitions = $this->buildLayerDefinitions($layersRaw);
        $this->rejectDuplicatePatterns($definitions);
        $registry = $this->buildRegistry($definitions);

        $layerNames = $registry->layerNames();
        $allowedTargets = $this->buildAllowedTargets($allowRaw, $layerNames, $logger);

        $policy = new LayerPolicy($allowedTargets);
        $coverage = $this->resolveCoverage($coverageRaw);

        $warnings = $this->reportMutualAllow($allowedTargets, $logger);

        return new ArchitectureFactoryResult(
            new ArchitectureConfiguration($registry, $policy, $coverage),
            $warnings,
        );
    }

    /**
     * Validates that {@code $raw} is an associative map whose keys are exactly
     * the well-known top-level architecture keys (`layers`, `allow`, `coverage`).
     *
     * @param array<string, mixed>|array<int, mixed> $raw
     */
    private function validateTopLevelStructure(array $raw): void
    {
        if (array_is_list($raw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture: must be a map with keys "layers", "allow", "coverage"; a sequential list is not allowed.',
            );
        }

        $unknown = [];
        foreach (array_keys($raw) as $key) {
            if (!\is_string($key) || !\in_array($key, self::ALLOWED_TOP_LEVEL_KEYS, true)) {
                $unknown[] = (string) $key;
            }
        }

        if ($unknown === []) {
            return;
        }

        throw new ConfigLoadException(
            self::CONFIG_PATH,
            \sprintf(
                'architecture: unknown %s %s. Allowed keys: %s.',
                \count($unknown) === 1 ? 'key' : 'keys',
                implode(', ', array_map(static fn(string $k): string => '"' . $k . '"', $unknown)),
                implode(', ', array_map(static fn(string $k): string => '"' . $k . '"', self::ALLOWED_TOP_LEVEL_KEYS)),
            ),
        );
    }

    /**
     * Parses the ordered `architecture.layers` list into {@see LayerDefinition}
     * instances, preserving declaration order.
     *
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
            $definitions[] = $this->buildSingleLayerDefinition($index, $entry, $seenNames);
        }

        return $definitions;
    }

    /**
     * @param array<string, true> $seenNames
     *
     * @param-out array<string, true> $seenNames
     */
    private function buildSingleLayerDefinition(int $index, mixed $entry, array &$seenNames): LayerDefinition
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

        $patterns = $this->normalizeLayerPatterns($index, $name, $entry['patterns'] ?? null);

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
    private function normalizeLayerPatterns(int $index, string $layerName, mixed $value): array
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
    private function rejectDuplicatePatterns(array $definitions): void
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

    /**
     * @param list<string> $layerNames Names from the registry; used for cross-validation.
     *
     * @return array<string, list<string>> Map source → list of allowed targets, deduplicated and self-references stripped.
     */
    private function buildAllowedTargets(mixed $allowRaw, array $layerNames, LoggerInterface $logger): array
    {
        if ($allowRaw === [] || $allowRaw === null) {
            return [];
        }

        if (!\is_array($allowRaw) || array_is_list($allowRaw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture.allow: must be a map of layer-name → list of target layer names.',
            );
        }

        $layerSet = array_flip($layerNames);
        $allowed = [];

        foreach ($allowRaw as $source => $targets) {
            if (!\is_string($source)) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    'architecture.allow: must be a map of layer-name → list of target layer names.',
                );
            }

            if (!isset($layerSet[$source])) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.allow.%s: unknown layer.', $source),
                );
            }

            $allowed[$source] = $this->normalizeAllowTargets($source, $targets, $layerSet, $logger);
        }

        return $allowed;
    }

    /**
     * @param array<string, int> $layerSet
     *
     * @return list<string>
     */
    private function normalizeAllowTargets(string $source, mixed $targets, array $layerSet, LoggerInterface $logger): array
    {
        if ($targets === null) {
            return [];
        }

        if (!\is_array($targets) || !array_is_list($targets)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.allow.%s: must be a list of target layer names.', $source),
            );
        }

        $result = [];
        $seen = [];
        foreach ($targets as $index => $entry) {
            $target = $this->normalizeAllowEntry($source, $index, $entry, $logger);

            // Self-reference: silently dedup (same-layer is always allowed by LayerPolicy).
            if ($target === $source) {
                continue;
            }

            if (!isset($layerSet[$target])) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf("architecture.allow.%s[%d]: unknown layer '%s'.", $source, $index, $target),
                );
            }

            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $result[] = $target;
        }

        return $result;
    }

    /**
     * Normalizes a single allow-list entry to a bare target name.
     *
     * Supports two forms:
     * - Short: bare string {@code 'service'}.
     * - Long:  associative array {@code [target: 'service', types: ['method_call']]}.
     *
     * The long form's {@code types} key is accepted for forward compatibility but
     * not yet enforced; if present, emit a PSR-3 warning.
     */
    private function normalizeAllowEntry(string $source, int $index, mixed $entry, LoggerInterface $logger): string
    {
        if (\is_string($entry)) {
            if ($entry === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.allow.%s[%d]: target must be a non-empty string.', $source, $index),
                );
            }

            return $entry;
        }

        if (\is_array($entry) && !array_is_list($entry) && isset($entry['target']) && \is_string($entry['target']) && $entry['target'] !== '') {
            if (\array_key_exists('types', $entry)) {
                $logger->warning(\sprintf(
                    "architecture.allow.%s: 'types' filter declared but not yet enforced (Phase 2).",
                    $source,
                ));
            }

            return $entry['target'];
        }

        throw new ConfigLoadException(
            self::CONFIG_PATH,
            \sprintf(
                "architecture.allow.%s[%d]: each target must be a layer name (string) or a map with a non-empty 'target' key.",
                $source,
                $index,
            ),
        );
    }

    private function resolveCoverage(mixed $coverageRaw): CoverageMode
    {
        if ($coverageRaw === null) {
            return CoverageMode::Ignore;
        }

        if (!\is_string($coverageRaw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.coverage: must be one of 'ignore', 'warn', 'error' (got %s).",
                    get_debug_type($coverageRaw),
                ),
            );
        }

        try {
            return CoverageMode::fromString($coverageRaw);
        } catch (InvalidArgumentException $e) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.coverage: must be one of 'ignore', 'warn', 'error' (got '%s').",
                    $coverageRaw,
                ),
                $e,
            );
        }
    }

    /**
     * Scans for symmetric {@code A → B} / {@code B → A} pairs.
     *
     * Mutual allow is legal but usually indicates that the two layers should
     * be merged. The warning is returned as a {@see DeferredWarning} for
     * downstream draining (see Step 1 of the follow-up plan) AND emitted via
     * the optional PSR-3 logger so existing callers (the configuration
     * pipeline) keep functioning during the transition.
     *
     * @param array<string, list<string>> $allowedTargets
     *
     * @return list<DeferredWarning>
     */
    private function reportMutualAllow(array $allowedTargets, LoggerInterface $logger): array
    {
        $pairs = [];
        $seen = [];

        foreach ($allowedTargets as $from => $targets) {
            foreach ($targets as $to) {
                if ($from === $to) {
                    continue;
                }

                if (!isset($allowedTargets[$to])) {
                    continue;
                }

                if (!\in_array($from, $allowedTargets[$to], true)) {
                    continue;
                }

                // Order-independent dedup: emit each pair only once.
                $key = $from < $to ? "{$from}|{$to}" : "{$to}|{$from}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $pairs[] = [$from, $to];
            }
        }

        if ($pairs === []) {
            return [];
        }

        $rendered = implode(', ', array_map(
            static fn(array $pair): string => "{$pair[0]} ↔ {$pair[1]}",
            $pairs,
        ));

        $message = \sprintf(
            'architecture.allow: mutual-allow detected between layer pair(s): %s. Consider merging the layers if this is unintentional.',
            $rendered,
        );

        $logger->warning($message);

        return [new DeferredWarning(LogLevel::WARNING, $message)];
    }
}
