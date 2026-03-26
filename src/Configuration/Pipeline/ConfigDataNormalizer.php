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
     * Normalizes nested YAML config data to flat dot-notation keys.
     *
     * @param array<string, mixed> $data Raw config data (after YAML parsing and key normalization)
     *
     * @return array<string, mixed> Flat dot-notation config values
     */
    public static function normalize(array $data): array
    {
        $result = [];

        // Direct keys: paths and exclude
        if (isset($data['paths'])) {
            $result['paths'] = $data['paths'];
        }
        if (isset($data['exclude'])) {
            $result['excludes'] = $data['exclude'];
        }

        // Cache section
        if (isset($data['cache']['dir'])) {
            $result['cache.dir'] = $data['cache']['dir'];
        }
        if (isset($data['cache']['enabled'])) {
            $result['cache.enabled'] = $data['cache']['enabled'];
        }

        // Format
        if (isset($data['format'])) {
            $result['format'] = $data['format'];
        }

        // Namespace section
        if (isset($data['namespace']['strategy'])) {
            $result['namespace.strategy'] = $data['namespace']['strategy'];
        }
        if (isset($data['namespace']['composerJson'])) {
            $result['namespace.composer_json'] = $data['namespace']['composerJson'];
        }

        // Aggregation section
        if (isset($data['aggregation']['prefixes'])) {
            $result['aggregation.prefixes'] = $data['aggregation']['prefixes'];
        }
        if (isset($data['aggregation']['autoDepth'])) {
            $result['aggregation.auto_depth'] = $data['aggregation']['autoDepth'];
        }

        // Rules section (pass as-is)
        if (isset($data['rules'])) {
            $result['rules'] = $data['rules'];
        }

        // Disabled/only rules
        if (isset($data['disabledRules'])) {
            $result['disabled_rules'] = $data['disabledRules'];
        }
        if (isset($data['onlyRules'])) {
            $result['only_rules'] = $data['onlyRules'];
        }

        // Exclude paths (violation suppression patterns)
        if (isset($data['excludePaths'])) {
            $result['exclude_paths'] = $data['excludePaths'];
        }

        // Computed metrics (pass as-is, resolved later by ComputedMetricsConfigResolver)
        if (isset($data['computed_metrics']) || isset($data['computedMetrics'])) {
            $result['computed_metrics'] = $data['computed_metrics'] ?? $data['computedMetrics'];
        }

        // Fail-on severity
        if (isset($data['failOn'])) {
            $result['fail_on'] = $data['failOn'];
        }

        // Exclude health dimensions
        if (isset($data['excludeHealth']) || isset($data['exclude_health'])) {
            $result['exclude_health'] = $data['excludeHealth'] ?? $data['exclude_health'];
        }

        // Include generated files
        if (isset($data['includeGenerated']) || isset($data['include_generated'])) {
            $result['include_generated'] = $data['includeGenerated'] ?? $data['include_generated'];
        }

        return $result;
    }
}
