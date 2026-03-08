<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Loader;

use AiMessDetector\Configuration\Exception\ConfigLoadException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader implements ConfigLoaderInterface
{
    private const array SUPPORTED_EXTENSIONS = ['yaml', 'yml'];

    /**
     * Allowed root keys in configuration file.
     * Supports both snake_case and camelCase variants.
     */
    private const array ALLOWED_ROOT_KEYS = [
        'rules',
        'cache',
        'format',
        'namespace',
        'aggregation',
        'disabledRules',
        'disabled_rules',
        'onlyRules',
        'only_rules',
        'paths',
        'exclude',
        'excludePaths',
        'exclude_paths',
    ];

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

        $this->validateStructure($content, $path);

        return $this->normalizeKeys($content);
    }

    public function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($extension, self::SUPPORTED_EXTENSIONS, true);
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
     * Validates the structure of the configuration.
     *
     * @param array<string, mixed> $config
     */
    private function validateStructure(array $config, string $path): void
    {
        // Check for unknown root keys
        $unknownKeys = array_diff(array_keys($config), self::ALLOWED_ROOT_KEYS);

        if ($unknownKeys !== []) {
            throw ConfigLoadException::invalidStructure(
                $path,
                \sprintf('Unknown configuration keys: %s', implode(', ', $unknownKeys)),
            );
        }

        // Validate 'rules' section structure
        if (isset($config['rules'])) {
            if (!\is_array($config['rules'])) {
                throw ConfigLoadException::invalidStructure(
                    $path,
                    '"rules" must be an associative array',
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

        // Validate 'cache' section structure
        if (isset($config['cache']) && !\is_array($config['cache'])) {
            throw ConfigLoadException::invalidStructure(
                $path,
                '"cache" must be an associative array',
            );
        }

        // Validate 'namespace' section structure
        if (isset($config['namespace']) && !\is_array($config['namespace'])) {
            throw ConfigLoadException::invalidStructure(
                $path,
                '"namespace" must be an associative array',
            );
        }

        // Validate 'aggregation' section structure
        if (isset($config['aggregation']) && !\is_array($config['aggregation'])) {
            throw ConfigLoadException::invalidStructure(
                $path,
                '"aggregation" must be an associative array',
            );
        }

        // Validate list fields
        $listFields = ['disabled_rules', 'disabledRules', 'only_rules', 'onlyRules', 'paths', 'exclude', 'exclude_paths', 'excludePaths'];
        foreach ($listFields as $field) {
            if (isset($config[$field]) && !\is_array($config[$field])) {
                throw ConfigLoadException::invalidStructure(
                    $path,
                    \sprintf('"%s" must be a list', $field),
                );
            }
        }
    }
}
