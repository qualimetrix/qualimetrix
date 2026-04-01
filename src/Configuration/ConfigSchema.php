<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

/**
 * Single source of truth for all valid configuration keys.
 *
 * Defines the mapping between YAML config structure and internal flat
 * dot-notation keys, plus type constraints for root-level keys.
 *
 * Both YamlConfigLoader (validation) and ConfigDataNormalizer (normalization)
 * derive their behavior from this schema, eliminating duplicated key lists.
 *
 * Root key types:
 * - 'section'  — must be an associative array (e.g., cache, namespace)
 * - 'list'     — must be a sequential array (e.g., paths, disabled_rules)
 * - 'scalar'   — string, int, bool, etc. (e.g., format, fail_on)
 * - 'mixed'    — can be either array or scalar (e.g., rules, computed_metrics)
 */
final class ConfigSchema
{
    /**
     * Root key type constraints (camelCase, post-normalization).
     *
     * @var array<string, string>
     */
    private const ROOT_KEY_TYPES = [
        // Sections (must be associative arrays)
        'cache' => 'section',
        'namespace' => 'section',
        'aggregation' => 'section',
        'coupling' => 'section',
        'parallel' => 'section',

        // Lists (must be sequential arrays)
        'paths' => 'list',
        'exclude' => 'list',
        'disabledRules' => 'list',
        'onlyRules' => 'list',
        'excludePaths' => 'list',
        'excludeHealth' => 'list',

        // Mixed (array with special structure)
        'rules' => 'mixed',
        'computedMetrics' => 'mixed',
        'computed_metrics' => 'mixed',

        // Scalars (string, int, bool)
        'format' => 'scalar',
        'failOn' => 'scalar',
        'memoryLimit' => 'scalar',
        'memory_limit' => 'scalar',
        'includeGenerated' => 'scalar',
        'include_generated' => 'scalar',
    ];

    /**
     * Mapping definitions: [sourceKeyPath, resultKey].
     *
     * Source key path can be:
     * - 'key'           — top-level key
     * - 'section.key'   — nested key (dot = nesting level)
     * - 'keyA|keyB'     — alternative keys (first found wins)
     *
     * @var list<array{string, string}>
     */
    public const MAPPINGS = [
        // Direct / renamed top-level keys
        ['paths', 'paths'],
        ['exclude', 'excludes'],
        ['format', 'format'],
        ['rules', 'rules'],
        ['disabledRules', 'disabled_rules'],
        ['onlyRules', 'only_rules'],
        ['excludePaths', 'exclude_paths'],
        ['failOn', 'fail_on'],

        // Nested sections (dot = nesting)
        ['cache.dir', 'cache.dir'],
        ['cache.enabled', 'cache.enabled'],
        ['namespace.strategy', 'namespace.strategy'],
        ['namespace.composerJson', 'namespace.composer_json'],
        ['aggregation.prefixes', 'aggregation.prefixes'],
        ['aggregation.autoDepth', 'aggregation.auto_depth'],
        ['parallel.workers', 'parallel.workers'],

        // Coupling section
        ['coupling.frameworkNamespaces|coupling.framework_namespaces', 'coupling.framework_namespaces'],

        // Dual-naming (camelCase | snake_case)
        ['computedMetrics|computed_metrics', 'computed_metrics'],
        ['excludeHealth|exclude_health', 'exclude_health'],
        ['includeGenerated|include_generated', 'include_generated'],
        ['memoryLimit|memory_limit', 'memory_limit'],
    ];

    /**
     * Returns the set of allowed root-level keys (camelCase, post-normalization).
     *
     * Derived from MAPPINGS and ROOT_KEY_TYPES.
     *
     * @return list<string>
     */
    public static function allowedRootKeys(): array
    {
        $keys = [];

        foreach (self::MAPPINGS as [$sourcePath]) {
            foreach (explode('|', $sourcePath) as $alt) {
                $root = str_contains($alt, '.') ? explode('.', $alt, 2)[0] : $alt;
                $keys[$root] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Returns root keys that must be associative arrays (sections).
     *
     * @return list<string>
     */
    public static function sectionKeys(): array
    {
        return self::keysByType('section');
    }

    /**
     * Returns root keys that must be sequential arrays (lists).
     *
     * @return list<string>
     */
    public static function listKeys(): array
    {
        return self::keysByType('list');
    }

    /**
     * @return list<string>
     */
    private static function keysByType(string $type): array
    {
        $keys = [];

        foreach (self::ROOT_KEY_TYPES as $key => $keyType) {
            if ($keyType === $type) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
