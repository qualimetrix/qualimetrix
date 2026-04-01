<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

/**
 * Normalizes YAML config data to flat dot-notation keys.
 *
 * Shared by ConfigFileStage and PresetStage to convert nested YAML structures
 * into the flat key format expected by ConfigurationPipeline.
 */
final class ConfigDataNormalizer
{
    /**
     * Mapping definitions: [sourceKeyPath, resultKey].
     *
     * Source key path can be:
     * - 'key'           — top-level key
     * - 'section.key'   — nested key (dot = nesting level)
     * - 'keyA|keyB'     — alternative keys (first found wins)
     *
     * This is the single source of truth for all valid config keys.
     * YamlConfigLoader derives its allowed root keys from this list.
     *
     * @var list<array{string, string}>
     */
    private const MAPPINGS = [
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
     * Derived from MAPPINGS — this is the single source of truth.
     * Used by YamlConfigLoader for unknown key validation.
     *
     * @return list<string>
     */
    public static function allowedRootKeys(): array
    {
        $keys = [];

        foreach (self::MAPPINGS as [$sourcePath]) {
            foreach (explode('|', $sourcePath) as $alt) {
                // Extract root segment: 'cache.dir' → 'cache', 'rules' → 'rules'
                $root = str_contains($alt, '.') ? explode('.', $alt, 2)[0] : $alt;
                $keys[$root] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Normalizes nested YAML config data to flat dot-notation keys.
     *
     * @param array<string, mixed> $data Raw config data (after YAML parsing and key normalization)
     *
     * @return array<string, mixed> Flat dot-notation config values
     */
    public static function normalize(array $data): array
    {
        $result = [];

        foreach (self::MAPPINGS as [$sourcePath, $resultKey]) {
            $value = self::resolve($data, $sourcePath);

            if ($value !== null) {
                $result[$resultKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Resolves a value from the data array using a source path.
     *
     * @param array<string, mixed> $data
     */
    private static function resolve(array $data, string $sourcePath): mixed
    {
        // Alternative keys: 'keyA|keyB'
        if (str_contains($sourcePath, '|')) {
            foreach (explode('|', $sourcePath) as $alt) {
                $value = self::resolve($data, $alt);

                if ($value !== null) {
                    return $value;
                }
            }

            return null;
        }

        // Nested key: 'section.key'
        if (str_contains($sourcePath, '.')) {
            [$section, $key] = explode('.', $sourcePath, 2);

            return isset($data[$section][$key]) ? $data[$section][$key] : null;
        }

        // Top-level key
        return $data[$sourcePath] ?? null;
    }
}
