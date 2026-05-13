<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;

/**
 * Converts the raw YAML map under the {@code architecture:} key into a typed
 * {@see ArchitectureConfiguration} value object.
 *
 * Responsibilities:
 * - Structural validation (top-level keys, maps vs. lists, key types, allowed values).
 * - Cross-validation between {@code layers} and {@code allow} (every reference
 *   in {@code allow} must name an existing layer).
 * - Long-form allow-entry normalization (`{target, types}` short-form → bare
 *   string; `types` is reserved for a future filter and currently triggers a
 *   deprecation-style warning).
 * - Mutual-allow detection (`A → B` AND `B → A`) — logged as a PSR-3 warning.
 * - Best-effort pattern-collision pre-validation: duplicate literal patterns
 *   across layers are rejected outright; same-prefix/same-specificity patterns
 *   trigger a logger warning.
 *
 * All structural errors surface as {@see ConfigLoadException} with the logical
 * path {@code 'architecture'} so that callers can route them through the same
 * channel as other configuration errors.
 *
 * The logger is optional. The factory falls back to a {@see NullLogger} when no
 * logger is provided. In production the
 * {@see \Qualimetrix\Configuration\Pipeline\ConfigurationPipeline} wires a
 * {@see \Qualimetrix\Infrastructure\Logging\DelegatingLogger} pointing at the
 * runtime {@see \Qualimetrix\Infrastructure\Logging\LoggerHolder}, so warnings
 * emitted here are forwarded to whichever logger the holder carries at log time.
 *
 * @qmx-threshold complexity.wmc error=100
 *                Comprehensive structural validation of a free-form YAML map
 *                produces many short, focused methods. WMC stays high because
 *                each validation step is its own method (preferable to one big
 *                validator). All methods are individually simple.
 */
final class ArchitectureConfigurationFactory
{
    private const string CONFIG_PATH = 'architecture';

    private const array ALLOWED_TOP_LEVEL_KEYS = ['layers', 'allow', 'coverage'];

    /**
     * Converts the merged YAML map under {@code architecture:} to a typed VO.
     *
     * Callers can pass {@code $merged['architecture'] ?? []} directly; both
     * associative and (degenerate) sequential arrays are accepted at the type
     * level and rejected by structural validation below.
     *
     * Unknown top-level keys (typos like {@code layres:} or unrecognized fields
     * like {@code imports:}) trigger a {@see ConfigLoadException} so that user
     * mistakes never silently disable architecture rules.
     *
     * Pre-validation of layer pattern collisions is best-effort and limited to
     * a heuristic check that detects exact-duplicate patterns and same-prefix
     * patterns across layers. Equal-specificity ambiguities that depend on
     * actual class FQNs are still surfaced at analyze-time by
     * {@see \Qualimetrix\Core\Architecture\Layer\LayerCollisionException}.
     *
     * @param array<string, mixed>|array<int, mixed> $raw
     */
    public function fromArray(array $raw, ?LoggerInterface $logger = null): ArchitectureConfiguration
    {
        $logger ??= new NullLogger();

        if ($raw === []) {
            return new ArchitectureConfiguration(
                new LayerRegistry([]),
                new LayerPolicy([]),
                CoverageMode::Ignore,
            );
        }

        $this->validateTopLevelStructure($raw);

        $layersRaw = $raw['layers'] ?? [];
        $allowRaw = $raw['allow'] ?? [];
        $coverageRaw = $raw['coverage'] ?? null;

        $definitions = $this->buildLayerDefinitions($layersRaw);
        $registry = $this->buildRegistry($definitions);

        $layerNames = $registry->layerNames();
        $allowedTargets = $this->buildAllowedTargets($allowRaw, $layerNames, $logger);

        $policy = new LayerPolicy($allowedTargets);
        $coverage = $this->resolveCoverage($coverageRaw);

        $this->reportMutualAllow($allowedTargets, $logger);
        $this->reportPatternCollisions($definitions, $logger);

        return new ArchitectureConfiguration($registry, $policy, $coverage);
    }

    /**
     * Validates that {@code $raw} is an associative map whose keys are exactly
     * the well-known top-level architecture keys (`layers`, `allow`, `coverage`).
     *
     * Sequential arrays and scalars are rejected outright. Unknown keys produce
     * a single exception listing all of them — typos like {@code layres:} would
     * otherwise be silently dropped, leaving the user with an apparently empty
     * architecture configuration.
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
                'architecture.layers: must be a map of layer-name → pattern(s), got ' . get_debug_type($layersRaw) . '.',
            );
        }

        if (array_is_list($layersRaw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture.layers: must be a map of layer-name → pattern(s); a sequential list is not allowed.',
            );
        }

        $definitions = [];
        foreach ($layersRaw as $name => $value) {
            if (\is_int($name)) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.layers: numeric keys are not allowed (got %d).', $name),
                );
            }

            $patterns = $this->normalizeLayerPatterns($name, $value);

            try {
                $definitions[] = new LayerDefinition($name, $patterns);
            } catch (InvalidLayerDefinitionException $e) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.layers.%s: %s', $name, $e->getMessage()),
                    $e,
                );
            }
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    private function normalizeLayerPatterns(string $layerName, mixed $value): array
    {
        if (\is_string($value)) {
            if ($value === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.layers.%s: pattern must be a non-empty string.', $layerName),
                );
            }

            return [$value];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers.%s: pattern must be a non-empty string or a list of strings, got %s.',
                    $layerName,
                    get_debug_type($value),
                ),
            );
        }

        if ($value === []) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers.%s: pattern list must contain at least one pattern.', $layerName),
            );
        }

        $patterns = [];
        foreach ($value as $index => $pattern) {
            if (!\is_string($pattern) || $pattern === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf(
                        'architecture.layers.%s: pattern must be a non-empty string at index %d (got %s).',
                        $layerName,
                        $index,
                        \is_string($pattern) ? "''" : get_debug_type($pattern),
                    ),
                );
            }
            $patterns[] = $pattern;
        }

        return $patterns;
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
     * Scans for symmetric {@code A → B} / {@code B → A} pairs and logs one warning
     * listing every such pair. Mutual allow is legal but usually indicates that
     * the two layers should be merged.
     *
     * @param array<string, list<string>> $allowedTargets
     */
    private function reportMutualAllow(array $allowedTargets, LoggerInterface $logger): void
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
            return;
        }

        $rendered = implode(', ', array_map(
            static fn(array $pair): string => "{$pair[0]} ↔ {$pair[1]}",
            $pairs,
        ));

        $logger->warning(\sprintf(
            'architecture.allow: mutual-allow detected between layer pair(s): %s. Consider merging the layers if this is unintentional.',
            $rendered,
        ));
    }

    /**
     * Best-effort pre-validation of pattern collisions between layers.
     *
     * Full collision detection across arbitrary patterns is intractable in
     * general (it depends on the universe of class FQNs that will actually
     * be analyzed), so this method applies cheap O(L²·P²) heuristics that
     * catch the practically important cases at config-load time:
     *
     * - **Exact-duplicate pattern**: the same literal pattern (after trailing
     *   `\` normalization) appears in two different layers. This is always a
     *   bug — any class matching that pattern will trigger a
     *   {@see \Qualimetrix\Core\Architecture\Layer\LayerCollisionException} at
     *   analyze time. Reported as a {@see ConfigLoadException}.
     *
     * - **Same prefix + same specificity**: two patterns in different layers
     *   share the literal prefix before their first wildcard and have equal
     *   specificity. They MAY collide on real class FQNs (e.g. {@code App\**\Foo}
     *   and {@code App\**\Bar} only collide if both wildcard segments land on
     *   the same class). Reported as a logger warning, not a hard error —
     *   false positives are acceptable.
     *
     * Equal-specificity ambiguities that only manifest at runtime against
     * specific class FQNs are still surfaced by {@see LayerRegistry} when
     * the rule runs.
     *
     * @param list<LayerDefinition> $definitions
     */
    private function reportPatternCollisions(array $definitions, LoggerInterface $logger): void
    {
        $count = \count($definitions);
        if ($count < 2) {
            return;
        }

        $warnings = [];
        $seenWarningKeys = [];

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $definitions[$i];
                $b = $definitions[$j];

                foreach ($a->patterns() as $patternA) {
                    $normalizedA = rtrim($patternA, '\\');
                    foreach ($b->patterns() as $patternB) {
                        $normalizedB = rtrim($patternB, '\\');

                        if ($normalizedA === $normalizedB) {
                            throw new ConfigLoadException(
                                self::CONFIG_PATH,
                                \sprintf(
                                    'architecture.layers: duplicate pattern "%s" declared in layers "%s" and "%s" — every class matching this pattern would collide between the two layers.',
                                    $normalizedA,
                                    $a->name(),
                                    $b->name(),
                                ),
                            );
                        }

                        if (!self::sharesPrefixAndSpecificity($normalizedA, $normalizedB)) {
                            continue;
                        }

                        $pairKey = $a->name() < $b->name()
                            ? $a->name() . '|' . $b->name() . '|' . $normalizedA . '|' . $normalizedB
                            : $b->name() . '|' . $a->name() . '|' . $normalizedB . '|' . $normalizedA;
                        if (isset($seenWarningKeys[$pairKey])) {
                            continue;
                        }
                        $seenWarningKeys[$pairKey] = true;

                        $warnings[] = \sprintf(
                            '"%s" (layer "%s") and "%s" (layer "%s")',
                            $normalizedA,
                            $a->name(),
                            $normalizedB,
                            $b->name(),
                        );
                    }
                }
            }
        }

        if ($warnings === []) {
            return;
        }

        $logger->warning(\sprintf(
            'architecture.layers: potential pattern collision detected — these patterns share the same literal prefix and specificity, so they may match the same class FQN: %s. Tighten the patterns to make layer assignment unambiguous.',
            implode('; ', $warnings),
        ));
    }

    /**
     * Returns true when two normalized patterns share the literal prefix before
     * their first wildcard character (`*`, `?`, `[`) AND have the same
     * specificity score (length of that literal prefix).
     *
     * A pattern with no wildcards has specificity equal to its full length, so
     * two prefix-mode patterns can only "share specificity" when they are
     * equal — that case is the exact-duplicate path handled by the caller.
     */
    private static function sharesPrefixAndSpecificity(string $a, string $b): bool
    {
        $wildcardA = self::firstWildcardPosition($a);
        $wildcardB = self::firstWildcardPosition($b);

        // Both pure-prefix patterns are only a collision when they're equal,
        // which the caller already covered via the duplicate-pattern check.
        if ($wildcardA === null || $wildcardB === null) {
            return false;
        }

        if ($wildcardA !== $wildcardB) {
            return false;
        }

        return substr($a, 0, $wildcardA) === substr($b, 0, $wildcardB);
    }

    private static function firstWildcardPosition(string $pattern): ?int
    {
        $best = null;
        foreach (['*', '?', '['] as $wildcard) {
            $position = strpos($pattern, $wildcard);
            if ($position === false) {
                continue;
            }
            if ($best === null || $position < $best) {
                $best = $position;
            }
        }

        return $best;
    }
}
