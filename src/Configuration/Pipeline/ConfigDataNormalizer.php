<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Qualimetrix\Configuration\ConfigSchema;

/**
 * Normalizes YAML config data to flat dot-notation keys.
 *
 * Shared by ConfigFileStage and PresetStage to convert nested YAML structures
 * into the flat key format expected by ConfigurationPipeline.
 *
 * Key mappings are defined in ConfigSchema::MAPPINGS (single source of truth).
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

        foreach (ConfigSchema::MAPPINGS as [$sourcePath, $resultKey]) {
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
