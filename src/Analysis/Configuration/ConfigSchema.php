<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Loader\SectionNormalizationPolicy;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigDataNormalizer;

/**
 * Single source of truth for all configuration keys.
 *
 * Every config key used anywhere in the pipeline is defined here as a constant.
 * The ENTRIES array unifies YAML-to-flat-key mappings with root key type constraints.
 *
 * Consumers (YamlConfigLoader, ConfigDataNormalizer, source stages, and owner resolvers)
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
    public const string SUPPRESS_PATHS = 'suppress_paths';
    public const string SUPPRESS_NAMESPACES = 'suppress_namespaces';
    public const string FAIL_ON = 'fail_on';
    public const string CACHE_DIR = 'cache.dir';
    public const string CACHE_ENABLED = 'cache.enabled';
    public const string PARALLEL_WORKERS = 'parallel.workers';
    public const string COUPLING = 'coupling';
    public const string COUPLING_FRAMEWORK_NAMESPACES = 'coupling.framework_namespaces';
    public const string COMPUTED_METRICS = 'computedMetrics';
    public const string EXCLUDE_HEALTH = 'excludeHealth';
    public const string INCLUDE_GENERATED = 'include_generated';
    public const string MEMORY_LIMIT = 'memory_limit';
    public const string ARCHITECTURE = 'architecture';

    // The architecture root is transported as a preserve-subtree associative
    // document. Its subject-owned syntax and merge semantics are not part of
    // this neutral schema.

    /**
     * Keys that are intentionally internal (no YAML path in ENTRIES).
     *
     * Used by tests to verify that every public constant either has an
     * ENTRIES row or is explicitly listed here.
     *
     * @var list<string>
     */
    public const array INTERNAL_KEYS = [];

    /** Capability-owned roots transported in ordered configuration documents. */
    public const array DOCUMENT_ROOTS = [self::COUPLING, self::COMPUTED_METRICS, self::EXCLUDE_HEALTH, self::ARCHITECTURE];

    // -------------------------------------------------------------------------
    // Root key types
    // -------------------------------------------------------------------------

    private const string LIST = 'list';
    private const string SCALAR = 'scalar';
    private const string MIXED = 'mixed';
    private const string BOOLEAN = 'boolean';
    private const string INTEGER = 'integer';
    private const string STRING = 'string';

    /**
     * Unified config entries: [sourcePath, resultKey, rootKeyType, scalarType].
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
     * Scalar type (only for keys whose leaf value must be a precise scalar):
     * - 'boolean' — must be a bool (cache.enabled, include_generated)
     * - 'integer' — must be an int (parallel.workers)
     * - 'string'  — must be a string (memory_limit)
     * - null      — no precise scalar type (list/mixed/section/loose-scalar keys)
     *
     * @var list<array{string, string, string|null, string|null}>
     */
    public const array ENTRIES = [
        // Top-level keys with explicit types
        [self::PATHS, self::PATHS, self::LIST, null],
        ['exclude', self::EXCLUDES, self::LIST, null],
        [self::FORMAT, self::FORMAT, self::SCALAR, null],
        [self::RULES, self::RULES, self::MIXED, null],
        ['disabledRules', self::DISABLED_RULES, self::LIST, null],
        ['onlyRules', self::ONLY_RULES, self::LIST, null],
        ['suppressPaths', self::SUPPRESS_PATHS, self::LIST, null],
        ['suppressNamespaces', self::SUPPRESS_NAMESPACES, self::LIST, null],
        ['failOn', self::FAIL_ON, self::SCALAR, null],

        // Section sub-keys (root type derived as 'section')
        ['cache.dir', self::CACHE_DIR, null, null],
        ['cache.enabled', self::CACHE_ENABLED, null, self::BOOLEAN],
        ['parallel.workers', self::PARALLEL_WORKERS, null, self::INTEGER],
        ['coupling.frameworkNamespaces', self::COUPLING_FRAMEWORK_NAMESPACES, null, null],

        // Top-level camelCase keys (loader normalizes snake_case before these are resolved)
        ['includeGenerated', self::INCLUDE_GENERATED, self::SCALAR, self::BOOLEAN],
        ['memoryLimit', self::MEMORY_LIMIT, self::SCALAR, self::STRING],

        // Architecture: free-form map with layers/allow/coverage sub-structure.
        // Treated as MIXED because sub-keys are user-defined layer names, not a fixed schema.
        [self::ARCHITECTURE, self::ARCHITECTURE, self::MIXED, null],
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

        return array_values(array_unique([...array_keys($keys), ...self::DOCUMENT_ROOTS]));
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
     * {@see \Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader} to decide
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
            'suppressPaths' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'suppressNamespaces' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'failOn' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            self::EXCLUDE_HEALTH => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'includeGenerated' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'memoryLimit' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,

            // Typed sections — sub-keys are schema-known options.
            'cache' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'parallel' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,
            'coupling' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,

            // Identifier sections — level-1 keys are user-defined identifiers
            // (rule slugs / metric names); level-2+ are typed option keys.
            self::RULES => SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN,
            self::COMPUTED_METRICS => SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN,

            // Architecture — PRESERVE_SUBTREE since Phase 3.5 (ADR 0009).
            // Closes the C1 max_expanded_layers scalar-leaf bug: the entire
            // descendant tree is preserved verbatim, so snake_case keys at
            // every depth (user-defined layer names, long-form target keys,
            // and the max_expanded_layers scalar leaf) reach downstream
            // consumers under the spelling the user wrote.
            self::ARCHITECTURE => SectionNormalizationPolicy::PRESERVE_SUBTREE,
        ];
    }

    /**
     * Option keys, at any depth below a section root, whose own keys are
     * user-written identifiers rather than schema-known options. The children
     * of such an option are preserved verbatim; anything deeper resumes
     * {@see SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE}, exactly as
     * {@see SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN} does one
     * level higher.
     *
     * `suppress_namespace_channels` is keyed by channel names, and channel
     * names are kebab. Sitting at level 3 of the `rules` section, its keys were
     * camelCased into names addressing no channel — `code-smell.boolean-argument`
     * reached the validator as `codeSmell.booleanArgument` and ended the run.
     * The reach is wider than the static vocabulary: the computed-metric name
     * validator *prescribes* kebab, so no computed metric could be named here
     * at all.
     *
     * Declared here rather than decided in the loader's traversal for the
     * reason ADR 0009 gives for the section policies themselves: which keys are
     * identifiers is a property of the schema, not of a walk.
     *
     * @return list<string> normalized (camelCase) option spellings, matched
     *                      against the normalized form of the key read, so
     *                      both spellings an author may write are covered
     */
    public static function identifierKeyedOptions(): array
    {
        return ['suppressNamespaceChannels'];
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
     * Returns root keys that must be associative maps (not scalars, not lists).
     *
     * Includes:
     * - Section keys (cache, parallel, coupling) — sub-keys
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

        $keys[self::COMPUTED_METRICS] = true;

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

        $lists[self::EXCLUDE_HEALTH] = true;

        return array_keys($lists);
    }

    /**
     * True when {@code $value} is well-typed for the given scalar marker
     * (one of the boolean|integer|string markers carried by {@see ENTRIES}).
     *
     * A marker unknown to the schema is a programming error and throws.
     */
    public static function matchesScalarType(mixed $value, string $scalarType): bool
    {
        return match ($scalarType) {
            self::BOOLEAN => \is_bool($value),
            self::INTEGER => \is_int($value),
            self::STRING => \is_string($value),
            default => throw new LogicException(\sprintf('Unknown scalar type marker "%s" in ConfigSchema::ENTRIES.', $scalarType)),
        };
    }

    /**
     * Human-readable scalar type name in the schema vocabulary (boolean,
     * integer, string, float, array, null), used in validation messages.
     */
    public static function scalarTypeName(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => self::BOOLEAN,
            \is_int($value) => self::INTEGER,
            \is_float($value) => 'float',
            \is_string($value) => self::STRING,
            \is_array($value) => 'array',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }
}
