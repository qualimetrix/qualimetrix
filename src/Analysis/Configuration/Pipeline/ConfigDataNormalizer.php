<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;

use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;

/**
 * Normalizes YAML config data to flat dot-notation keys.
 *
 * Shared by ConfigFileStage and PresetStage to convert nested YAML structures
 * into the flat key format expected by ConfigurationPipeline.
 *
 * Key mappings are defined in ConfigSchema::ENTRIES (single source of truth).
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
        $document = self::omittingUnwrittenKeys($data);
        $result = [];

        foreach (ConfigSchema::ENTRIES as [$sourcePath, $resultKey]) {
            $value = self::resolve($document, $sourcePath);

            if ($value !== null) {
                $result[$resultKey] = $value;
            }
        }

        foreach (ConfigSchema::DOCUMENT_ROOTS as $root) {
            if (\array_key_exists($root, $document)) {
                $result[$root] = $document[$root];
            }
        }

        return $result;
    }

    /**
     * Reads `key: ~` as a key the author never wrote, at every depth.
     *
     * One spelling of `~` used to mean two things: a key reached through
     * ConfigSchema::ENTRIES fell back to its default, while a key reached
     * through ConfigSchema::DOCUMENT_ROOTS kept its null and reached the root
     * owner, which refuses a value that is not a map. Dropping such entries
     * here gives both paths the same reading.
     *
     * Two deliberate exemptions:
     *
     *  - the `rules:` subtree is passed through untouched — a null rule body
     *    there is an authored value meaning "enable with the defaults", not an
     *    absent key;
     *  - a null *list element* survives. An element is a value, not a key, so
     *    "never written" says nothing about it, and silently dropping it would
     *    turn a malformed list into an accepted one.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function omittingUnwrittenKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $result[$key] = $key !== ConfigSchema::RULES && \is_array($value)
                ? self::omittingUnwrittenEntries($value)
                : $value;
        }

        return $result;
    }

    /**
     * @param array<string|int, mixed> $subtree
     *
     * @return array<string|int, mixed>
     */
    private static function omittingUnwrittenEntries(array $subtree): array
    {
        // A selector is an authored one-entry mapping. Its value is validated
        // by SelectorYamlDecoder, including a null that must be refused as a
        // malformed selector rather than silently erased as an unwritten key.
        if (self::isSelectorMapping($subtree)) {
            return $subtree;
        }

        $result = [];

        foreach ($subtree as $key => $value) {
            if ($value === null && \is_string($key)) {
                continue;
            }

            $result[$key] = \is_array($value) ? self::omittingUnwrittenEntries($value) : $value;
        }

        return $result;
    }

    /** @param array<string|int, mixed> $value */
    private static function isSelectorMapping(array $value): bool
    {
        if (\count($value) !== 1) {
            return false;
        }

        $kind = array_key_first($value);

        return \is_string($kind) && \in_array($kind, ['exact', 'subtree', 'regex'], true);
    }

    /**
     * Resolves a value from the data array using a source path.
     *
     * Source paths are camelCase (YamlConfigLoader normalizes before this runs).
     *
     * @param array<string, mixed> $data
     */
    private static function resolve(array $data, string $sourcePath): mixed
    {
        // Nested key: 'section.key'
        if (str_contains($sourcePath, '.')) {
            [$section, $key] = explode('.', $sourcePath, 2);

            return isset($data[$section][$key]) ? $data[$section][$key] : null;
        }

        // Top-level key
        return $data[$sourcePath] ?? null;
    }
}
