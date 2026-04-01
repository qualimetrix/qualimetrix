<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Loader;

use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader implements ConfigLoaderInterface
{
    private const array SUPPORTED_EXTENSIONS = ['yaml', 'yml'];

    public function load(string $path): array
    {
        if (!file_exists($path)) {
            throw ConfigLoadException::fileNotFound($path);
        }

        if (!is_readable($path)) {
            throw new ConfigLoadException($path, \sprintf('Configuration file is not readable: %s', $path));
        }

        try {
            $content = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw ConfigLoadException::parseError($path, $e->getMessage(), $e);
        }

        if (!\is_array($content)) {
            if ($content === null) {
                // Empty file is valid, return empty config
                return [];
            }
            throw ConfigLoadException::invalidFormat($path, 'YAML');
        }

        // Build reverse map (normalizedKey → originalKey) for user-facing error messages
        $keyMap = $this->buildRootKeyMap($content);

        $normalized = $this->normalizeKeys($content);

        // Validate after key normalization so we only need camelCase allowed keys
        // (derived from ConfigSchema — single source of truth)
        $this->validateStructure($normalized, $path, $keyMap);

        return $normalized;
    }

    public function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Builds a map of normalizedKey → originalKey for root-level keys.
     *
     * Used to show the user's original key names in error messages,
     * even though validation runs on normalized (camelCase) keys.
     *
     * @param array<string, mixed> $config Raw YAML config
     *
     * @return array<string, string> normalizedKey → originalKey
     */
    private function buildRootKeyMap(array $config): array
    {
        $map = [];

        foreach (array_keys($config) as $originalKey) {
            $map[$this->snakeToCamel((string) $originalKey)] = (string) $originalKey;
        }

        return $map;
    }

    /**
     * Recursively normalizes snake_case keys to camelCase.
     *
     * Keys that are rule identifiers (contain `.`) are preserved as-is,
     * because rule names use `group.rule-name` format and must not be normalized.
     * Their nested option values are still normalized.
     *
     * @param array<string, mixed> $config
     * @param bool $preserveKeys When true, keys at this level are preserved (used for rule name keys)
     *
     * @return array<string, mixed>
     */
    private function normalizeKeys(array $config, bool $preserveKeys = false): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            $stringKey = (string) $key;
            $normalizedKey = $preserveKeys ? $stringKey : $this->snakeToCamel($stringKey);

            if (\is_array($value)) {
                // When entering the 'rules' section, preserve rule name keys (next level)
                $isRulesSection = !$preserveKeys && $stringKey === 'rules';
                $result[$normalizedKey] = $this->normalizeKeys($value, $isRulesSection);
            } else {
                $result[$normalizedKey] = $value;
            }
        }

        return $result;
    }

    private function snakeToCamel(string $input): string
    {
        // Convert snake_case and kebab-case to camelCase, but preserve already camelCase keys
        return lcfirst(str_replace(['_', '-'], '', ucwords($input, '_-')));
    }

    /**
     * Resolves the original key name from the reverse map.
     *
     * @param array<string, string> $keyMap normalizedKey → originalKey
     */
    private function originalKey(string $normalizedKey, array $keyMap): string
    {
        return $keyMap[$normalizedKey] ?? $normalizedKey;
    }

    /**
     * Validates the structure of the normalized configuration.
     *
     * Allowed root keys, section keys, and list keys are all derived from
     * ConfigSchema (single source of truth).
     *
     * @param array<string, mixed> $config Post-normalization config (camelCase keys)
     * @param array<string, string> $keyMap normalizedKey → originalKey for error messages
     */
    private function validateStructure(array $config, string $path, array $keyMap): void
    {
        // Check for unknown root keys
        $unknownKeys = array_diff(
            array_keys($config),
            ConfigSchema::allowedRootKeys(),
        );

        if ($unknownKeys !== []) {
            $originalNames = array_map(
                fn(string $key): string => $this->originalKey($key, $keyMap),
                $unknownKeys,
            );

            throw ConfigLoadException::invalidStructure(
                $path,
                \sprintf('Unknown configuration keys: %s', implode(', ', $originalNames)),
            );
        }

        // Validate 'rules' section structure
        if (isset($config['rules'])) {
            if (!\is_array($config['rules'])) {
                throw ConfigLoadException::invalidStructure(
                    $path,
                    \sprintf('"%s" must be an associative array', $this->originalKey('rules', $keyMap)),
                );
            }

            foreach ($config['rules'] as $ruleName => $ruleConfig) {
                if (!\is_array($ruleConfig) && !\is_bool($ruleConfig) && $ruleConfig !== null) {
                    throw ConfigLoadException::invalidStructure(
                        $path,
                        \sprintf('Rule "%s" configuration must be an array, boolean, or null', $ruleName),
                    );
                }
            }
        }

        // Validate section keys that must be associative arrays (derived from ConfigSchema)
        foreach (ConfigSchema::sectionKeys() as $section) {
            if (isset($config[$section]) && !\is_array($config[$section])) {
                throw ConfigLoadException::invalidStructure(
                    $path,
                    \sprintf('"%s" must be an associative array', $this->originalKey($section, $keyMap)),
                );
            }
        }

        // Validate list fields (derived from ConfigSchema)
        foreach (ConfigSchema::listKeys() as $field) {
            if (isset($config[$field]) && !\is_array($config[$field])) {
                throw ConfigLoadException::invalidStructure(
                    $path,
                    \sprintf('"%s" must be a list', $this->originalKey($field, $keyMap)),
                );
            }
        }
    }
}
