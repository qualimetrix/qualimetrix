<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use LogicException;
use Qualimetrix\Configuration\Loader\SectionNormalizationPolicy;

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
    // {@see \Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory}
    // alongside `layers`, `allow`, and `coverage`. The default value is
    // {@see \Qualimetrix\Architecture\Domain\ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS}.
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
     * Returns the exhaustive normalization policy map: every root key in
     * {@see self::allowedRootKeys()} has exactly one entry. The map is the
     * single source of truth consulted by
     * {@see \Qualimetrix\Configuration\Loader\YamlConfigLoader} to decide
     * whether (and how deeply) snake_case → camelCase normalization applies.
     *
     * Adding a new root key MUST add a row here; the
     * {@see self::policyFor()} lookup throws on missing entries, and a
     * coverage-invariant test asserts exact equality between
     * {@see self::allowedRootKeys()} and the keys of this map.
     *
     * See [ADR 0009](../../docs/adr/0009-yaml-loader-normalization-model.md).
     *
     * @return array<string, SectionNormalizationPolicy>
     */
    public static function sectionPolicies(): array
    {
        return [
            // Top-level lists / scalars — leaf keys are typed; normalize.
            // List items have integer keys (no normalization needed); the
            // policy is consistent regardless.
            self::PATHS => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'exclude' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            self::FORMAT => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'disabledRules' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'onlyRules' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'excludePaths' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'excludeNamespaces' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'failOn' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'excludeHealth' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'includeGenerated' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'memoryLimit' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,

            // Typed sections — sub-keys are schema-known options.
            'cache' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'namespace' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'aggregation' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'parallel' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'coupling' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,

            // Identifier sections — level-1 keys are user-defined identifiers
            // (rule slugs / metric names); level-2+ are typed option keys.
            self::RULES => SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN,
            'computedMetrics' => SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN,

            // Architecture — currently NORMALIZE; the architecture.allow
            // subtree is preserved by a separate sub-path opt-out
            // ({@see self::nestedIdentifierKeyPaths()}) during the Phase 3
            // transition. Phase 3.5 migrates this entry to PRESERVE_SUBTREE
            // and the sub-path opt-out becomes empty.
            self::ARCHITECTURE => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
        ];
    }

    /**
     * Returns the policy for a single root key. Fails fast with
     * {@see LogicException} when the key has no registered policy — the
     * intended behavior for any new root added without updating
     * {@see self::sectionPolicies()}.
     */
    public static function policyFor(string $rootKey): SectionNormalizationPolicy
    {
        $policies = self::sectionPolicies();

        if (!isset($policies[$rootKey])) {
            throw new LogicException(\sprintf(
                'No SectionNormalizationPolicy registered for root key "%s". '
                . 'Add an entry to ConfigSchema::sectionPolicies() — every key in allowedRootKeys() '
                . 'must declare its normalization policy (ADR 0009).',
                $rootKey,
            ));
        }

        return $policies[$rootKey];
    }

    /**
     * Returns root keys whose child keys are identifiers (rule/metric names)
     * that must not be normalized to camelCase.
     *
     * @deprecated Phase 3.6 removes this wrapper; consumers should query
     *             {@see self::sectionPolicies()} directly.
     *
     * @return list<string>
     */
    public static function identifierKeySections(): array
    {
        $sections = [];

        foreach (self::sectionPolicies() as $key => $policy) {
            if ($policy === SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN) {
                $sections[] = $key;
            }
        }

        return $sections;
    }

    /**
     * Returns dot-separated paths whose entire subtree is preserved verbatim
     * during key normalization.
     *
     * Independent of {@see self::sectionPolicies()} during the Phase 3
     * transition because the policy enum operates at the root level only;
     * the {@code architecture.allow} sub-path preservation is the only
     * remaining sub-path opt-out and becomes obsolete in Phase 3.5 when
     * {@code architecture} migrates to {@code PRESERVE_SUBTREE} and the
     * entire architecture subtree is preserved by policy.
     *
     * @deprecated Phase 3.6 removes this wrapper; preservation responsibility
     *             moves entirely to {@see self::sectionPolicies()}.
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
