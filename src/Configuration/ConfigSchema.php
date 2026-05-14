<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

/**
 * Single source of truth for all configuration keys.
 *
 * Every config key used anywhere in the pipeline is defined here as a constant.
 * The ENTRIES array unifies YAML-to-flat-key mappings with root key type constraints.
 *
 * Consumers (YamlConfigLoader, ConfigDataNormalizer, AnalysisConfiguration,
 * DefaultsStage, CliStage, ConfigurationMerger, ConfigurationPipeline)
 * all reference these constants instead of string literals.
 *
 * Adding a new config option:
 * 1. Add a constant below
 * 2. Add an entry to ENTRIES (if YAML-configurable)
 * 3. Add handling in the appropriate consumer
 */
final class ConfigSchema
{
    // -------------------------------------------------------------------------
    // Result keys (flat dot-notation, used after normalization)
    // -------------------------------------------------------------------------

    public const string PATHS = 'paths';
    public const string EXCLUDES = 'excludes';
    public const string FORMAT = 'format';
    public const string RULES = 'rules';
    public const string DISABLED_RULES = 'disabled_rules';
    public const string ONLY_RULES = 'only_rules';
    public const string EXCLUDE_PATHS = 'exclude_paths';
    public const string EXCLUDE_NAMESPACES = 'exclude_namespaces';
    public const string FAIL_ON = 'fail_on';
    public const string CACHE_DIR = 'cache.dir';
    public const string CACHE_ENABLED = 'cache.enabled';
    public const string NAMESPACE_STRATEGY = 'namespace.strategy';
    public const string NAMESPACE_COMPOSER_JSON = 'namespace.composer_json';
    public const string AGGREGATION_PREFIXES = 'aggregation.prefixes';
    public const string AGGREGATION_AUTO_DEPTH = 'aggregation.auto_depth';
    public const string PARALLEL_WORKERS = 'parallel.workers';
    public const string COUPLING_FRAMEWORK_NAMESPACES = 'coupling.framework_namespaces';
    public const string COMPUTED_METRICS = 'computed_metrics';
    public const string EXCLUDE_HEALTH = 'exclude_health';
    public const string INCLUDE_GENERATED = 'include_generated';
    public const string MEMORY_LIMIT = 'memory_limit';
    public const string ARCHITECTURE = 'architecture';

    // Note: `architecture.max_expanded_layers` (Phase 2 direction 2) is a
    // sub-key under the MIXED `architecture` root and lives in its sibling
    // {@see \Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory}
    // alongside `layers`, `allow`, and `coverage`. The default value is
    // {@see \Qualimetrix\Core\Architecture\ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS}.
    // It does not appear in ENTRIES because sub-keys of a MIXED root are
    // validated by the dedicated factory, not by the generic schema.

    /** Internal key: set by DefaultsStage only, not YAML-configurable. */
    public const string PROJECT_ROOT = 'project_root';

    /**
     * Keys that are intentionally internal (no YAML path in ENTRIES).
     *
     * Used by tests to verify that every public constant either has an
     * ENTRIES row or is explicitly listed here.
     *
     * @var list<string>
     */
    public const array INTERNAL_KEYS = [self::PROJECT_ROOT];

    // -------------------------------------------------------------------------
    // Root key types
    // -------------------------------------------------------------------------

    private const string LIST = 'list';
    private const string SCALAR = 'scalar';
    private const string MIXED = 'mixed';

    /**
     * Unified config entries: [sourcePath, resultKey, rootKeyType].
     *
     * Source key path (camelCase — YamlConfigLoader normalizes snake_case before
     * ConfigDataNormalizer sees the data, so only camelCase paths are needed):
     * - 'key'           — top-level key
     * - 'section.key'   — nested key (dot = nesting level; root is auto-typed as section)
     *
     * Root key type:
     * - 'list'    — must be a sequential array (paths, disabled_rules, etc.)
     * - 'scalar'  — string, int, bool (format, fail_on, etc.)
     * - 'mixed'   — array with special structure (rules, computed_metrics)
     * - null      — sub-key of a section (root is auto-typed as section)
     *
     * @var list<array{string, string, string|null}>
     */
    public const array ENTRIES = [
        // Top-level keys with explicit types
        [self::PATHS, self::PATHS, self::LIST],
        ['exclude', self::EXCLUDES, self::LIST],
        [self::FORMAT, self::FORMAT, self::SCALAR],
        [self::RULES, self::RULES, self::MIXED],
        ['disabledRules', self::DISABLED_RULES, self::LIST],
        ['onlyRules', self::ONLY_RULES, self::LIST],
        ['excludePaths', self::EXCLUDE_PATHS, self::LIST],
        ['excludeNamespaces', self::EXCLUDE_NAMESPACES, self::LIST],
        ['failOn', self::FAIL_ON, self::SCALAR],

        // Section sub-keys (root type derived as 'section')
        ['cache.dir', self::CACHE_DIR, null],
        ['cache.enabled', self::CACHE_ENABLED, null],
        ['namespace.strategy', self::NAMESPACE_STRATEGY, null],
        ['namespace.composerJson', self::NAMESPACE_COMPOSER_JSON, null],
        ['aggregation.prefixes', self::AGGREGATION_PREFIXES, null],
        ['aggregation.autoDepth', self::AGGREGATION_AUTO_DEPTH, null],
        ['parallel.workers', self::PARALLEL_WORKERS, null],
        ['coupling.frameworkNamespaces', self::COUPLING_FRAMEWORK_NAMESPACES, null],

        // Top-level camelCase keys (loader normalizes snake_case before these are resolved)
        ['computedMetrics', self::COMPUTED_METRICS, self::MIXED],
        ['excludeHealth', self::EXCLUDE_HEALTH, self::LIST],
        ['includeGenerated', self::INCLUDE_GENERATED, self::SCALAR],
        ['memoryLimit', self::MEMORY_LIMIT, self::SCALAR],

        // Architecture: free-form map with layers/allow/coverage sub-structure.
        // Treated as MIXED because sub-keys are user-defined layer names, not a fixed schema.
        [self::ARCHITECTURE, self::ARCHITECTURE, self::MIXED],
    ];

    /**
     * Returns the set of allowed root-level keys (camelCase, post-normalization).
     *
     * @return list<string>
     */
    public static function allowedRootKeys(): array
    {
        $keys = [];

        foreach (self::ENTRIES as [$sourcePath]) {
            $root = str_contains($sourcePath, '.') ? explode('.', $sourcePath, 2)[0] : $sourcePath;
            $keys[$root] = true;
        }

        return array_keys($keys);
    }

    /**
     * Returns root keys that must be associative arrays.
     *
     * Includes both explicitly typed sections and roots derived from dotted sub-keys.
     *
     * @return list<string>
     */
    public static function sectionKeys(): array
    {
        $sections = [];

        // Any entry with a dotted source path implies its root is a section
        foreach (self::ENTRIES as [$sourcePath, , $type]) {
            if ($type !== null) {
                continue;
            }

            if (str_contains($sourcePath, '.')) {
                $sections[explode('.', $sourcePath, 2)[0]] = true;
            }
        }

        return array_keys($sections);
    }

    /**
     * Returns allowed sub-keys for each section (camelCase, post-normalization).
     *
     * Derived from ENTRIES: entries with null type and dotted sourcePath are section sub-keys.
     *
     * @return array<string, list<string>> section => [subKey, ...]
     */
    public static function allowedSectionSubKeys(): array
    {
        $result = [];

        foreach (self::ENTRIES as [$sourcePath, , $type]) {
            if ($type !== null || !str_contains($sourcePath, '.')) {
                continue;
            }

            [$section, $subKey] = explode('.', $sourcePath, 2);
            $result[$section][] = $subKey;
        }

        return $result;
    }

    /**
     * Returns root keys whose child keys are identifiers (rule/metric names)
     * that must not be normalized to camelCase.
     *
     * @return list<string>
     */
    public static function identifierKeySections(): array
    {
        return [self::RULES, 'computedMetrics'];
    }

    /**
     * Returns dot-separated paths whose immediate children are user-defined
     * identifiers and must NOT be normalized to camelCase.
     *
     * Unlike {@see identifierKeySections()}, these are nested below the root:
     * the path {@code architecture.allow} means the keys under
     * {@code architecture.allow.*} (source layer names) are preserved verbatim.
     *
     * Preserving layer names ensures that error messages and downstream
     * consumers see the names exactly as the user typed them — without this,
     * a configuration line like {@code app_core: [domain]} would be silently
     * turned into {@code appCore} during key normalization.
     *
     * Note: under ADR 0006 (declaration-order matching) `architecture.layers`
     * is an ordered list of entries (each with a `name` field), not a map.
     * List items have no user-defined keys, so the path no longer needs
     * preservation.
     *
     * @return list<string>
     */
    public static function nestedIdentifierKeyPaths(): array
    {
        return [
            self::ARCHITECTURE . '.allow',
        ];
    }

    /**
     * Returns root keys that must be associative maps (not scalars, not lists).
     *
     * Includes:
     * - Section keys (cache, namespace, aggregation, parallel, coupling) — sub-keys
     *   are a fixed schema validated by {@see allowedSectionSubKeys()}.
     * - MIXED roots whose sub-keys are user-defined identifiers (rules,
     *   computed_metrics, architecture).
     *
     * The YAML loader uses this list to reject scalars and sequential lists for
     * these keys at load time, so downstream consumers always see well-typed input.
     *
     * @return list<string>
     */
    public static function associativeRootKeys(): array
    {
        $keys = [];

        foreach (self::sectionKeys() as $section) {
            $keys[$section] = true;
        }

        // MIXED roots are associative maps (sub-keys are user-defined identifiers).
        foreach (self::ENTRIES as [$sourcePath, , $type]) {
            if ($type !== self::MIXED) {
                continue;
            }

            $root = str_contains($sourcePath, '.') ? explode('.', $sourcePath, 2)[0] : $sourcePath;
            $keys[$root] = true;
        }

        return array_keys($keys);
    }

    /**
     * Returns root keys that must be sequential arrays.
     *
     * @return list<string>
     */
    public static function listKeys(): array
    {
        $lists = [];

        foreach (self::ENTRIES as [$sourcePath, , $type]) {
            if ($type === self::LIST) {
                $root = str_contains($sourcePath, '.') ? explode('.', $sourcePath, 2)[0] : $sourcePath;
                $lists[$root] = true;
            }
        }

        return array_keys($lists);
    }
}
