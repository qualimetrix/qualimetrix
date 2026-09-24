<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader implements ConfigLoaderInterface
{
    private const array SUPPORTED_EXTENSIONS = ['yaml', 'yml'];

    public function load(string $path): array
    {
        if (!file_exists($path)) {
            throw ConfigurationRefusal::aboutConfigFileDocument(
                $path,
                \sprintf('Configuration file not found: %s', $path),
            );
        }

        if (!is_readable($path)) {
            throw ConfigurationRefusal::aboutConfigFileDocument(
                $path,
                \sprintf('Configuration file is not readable: %s', $path),
            );
        }

        try {
            $content = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw ConfigurationRefusal::aboutConfigFileDocument(
                $path,
                \sprintf('Failed to parse configuration file %s: %s', $path, $e->getMessage()),
                $e,
            );
        }

        if (!\is_array($content)) {
            if ($content === null) {
                // Empty file is valid, return empty config
                return [];
            }
            throw ConfigurationRefusal::aboutConfigFileDocument(
                $path,
                \sprintf('Configuration file %s is not valid %s format', $path, 'YAML'),
            );
        }

        // Build reverse map (normalizedKey → originalKey) for user-facing error messages
        $keyMap = $this->buildRootKeyMap($content);

        $normalized = DocumentKeyNormalizer::normalize($content, $path);

        // Validate after key normalization so we only need camelCase allowed keys
        // (derived from ConfigSchema — single source of truth)
        $this->validateStructure($normalized, $path, $keyMap, $content);

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
            $map[ConfigKeySpelling::normalize((string) $originalKey)] = (string) $originalKey;
        }

        return $map;
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
     * @param array<string, mixed> $rawConfig Pre-normalization config for sub-key error messages
     */
    private function validateStructure(array $config, string $path, array $keyMap, array $rawConfig): void
    {
        $this->validateRootKeys($config, $path, $keyMap);
        $this->validateRulesSection($config, $path, $keyMap, $rawConfig);
        RootContainerShapes::refuseWrongContainer($config, $path, $keyMap);
        $this->validateSectionSubKeys($config, $path, $rawConfig);
        $this->validateScalarTypes($config, $path);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     */
    private function validateRootKeys(array $config, string $path, array $keyMap): void
    {
        $allowedRootKeys = ConfigSchema::allowedRootKeys();
        $unknownKeys = array_diff(array_keys($config), $allowedRootKeys);

        if ($unknownKeys === []) {
            return;
        }

        RetiredSuppressionOptions::refuseRootKey($unknownKeys, $path, $keyMap);

        $messages = [];
        foreach ($unknownKeys as $key) {
            $original = $this->originalKey($key, $keyMap);
            $suggestion = self::suggestSimilarKey($original, self::spelledLike($allowedRootKeys, $original));
            $messages[] = $suggestion !== null
                ? \sprintf('"%s" (did you mean "%s"?)', $original, $suggestion)
                : \sprintf('"%s"', $original);
        }

        $firstOriginal = $this->originalKey($unknownKeys[array_key_first($unknownKeys)], $keyMap);

        throw ConfigurationRefusal::atConfigFileKey(
            $path,
            RefusedPosition::closed([$firstOriginal], $firstOriginal, self::spelledLike($allowedRootKeys, $firstOriginal)),
            \sprintf('Unknown configuration %s: %s', \count($messages) === 1 ? 'key' : 'keys', implode(', ', $messages)),
        );
    }

    /**
     * The raw section is handed to {@see RetiredSuppressionOptions} rather than the
     * normalized one: the three spellings of an option key have already
     * collapsed into one by then, and that refusal exists to answer in the
     * author's own.
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     * @param array<string, mixed> $rawConfig
     */
    private function validateRulesSection(array $config, string $path, array $keyMap, array $rawConfig): void
    {
        if (!isset($config[ConfigSchema::RULES])) {
            return;
        }

        if (!\is_array($config[ConfigSchema::RULES])) {
            $originalRulesKey = $this->originalKey(ConfigSchema::RULES, $keyMap);

            throw ConfigurationRefusal::atConfigFileKey(
                $path,
                RefusedPosition::open([$originalRulesKey], $originalRulesKey),
                \sprintf('"%s" must be an associative array', $originalRulesKey),
            );
        }

        foreach ($config[ConfigSchema::RULES] as $ruleName => $ruleConfig) {
            if (!\is_array($ruleConfig) && !\is_bool($ruleConfig) && $ruleConfig !== null) {
                $originalRulesKey = $this->originalKey(ConfigSchema::RULES, $keyMap);
                $ruleNameString = (string) $ruleName;

                throw ConfigurationRefusal::atConfigFileKey(
                    $path,
                    RefusedPosition::open([$originalRulesKey, $ruleNameString], $ruleNameString),
                    \sprintf('Rule "%s" configuration must be an array, boolean, or null', $ruleName),
                );
            }
        }

        RetiredSuppressionOptions::refuseInRules($rawConfig, $this->originalKey(ConfigSchema::RULES, $keyMap), $path);
    }

    /**
     * Validates that section sub-keys are known.
     *
     * @param array<string, mixed> $config Post-normalization config
     * @param array<string, mixed> $rawConfig Pre-normalization config for original key names
     */
    private function validateSectionSubKeys(array $config, string $path, array $rawConfig): void
    {
        foreach (ConfigSchema::allowedSectionSubKeys() as $section => $allowedSubKeys) {
            if (!isset($config[$section]) || !\is_array($config[$section])) {
                continue;
            }

            $unknownSubKeys = array_diff(array_keys($config[$section]), $allowedSubKeys);

            if ($unknownSubKeys === []) {
                continue;
            }

            // Find original section name for error message
            $originalSection = $this->findOriginalSectionName($section, $rawConfig);

            $messages = [];
            foreach ($unknownSubKeys as $subKey) {
                $originalSubKey = $this->findOriginalSubKey($section, $subKey, $rawConfig);
                $suggestion = self::suggestSimilarKey($originalSubKey, self::spelledLike($allowedSubKeys, $originalSubKey));
                $messages[] = $suggestion !== null
                    ? \sprintf('"%s" (did you mean "%s"?)', $originalSubKey, $suggestion)
                    : \sprintf('"%s"', $originalSubKey);
            }

            $firstOriginalSubKey = $this->findOriginalSubKey($section, $unknownSubKeys[array_key_first($unknownSubKeys)], $rawConfig);
            $allowedLikeAuthor = self::spelledLike($allowedSubKeys, $firstOriginalSubKey);

            throw ConfigurationRefusal::atConfigFileKey(
                $path,
                RefusedPosition::closed(
                    [$originalSection, $firstOriginalSubKey],
                    $firstOriginalSubKey,
                    $allowedLikeAuthor,
                ),
                \sprintf(
                    'Unknown %s in "%s" section: %s. Allowed keys: %s',
                    \count($messages) === 1 ? 'key' : 'keys',
                    $originalSection,
                    implode(', ', $messages),
                    implode(', ', $allowedLikeAuthor),
                ),
            );
        }
    }

    /**
     * Validates that scalar-typed leaves (cache.enabled, parallel.workers,
     * include_generated, memory_limit) carry their documented value type.
     *
     * This closes the silent-misconfiguration class where a wrong-typed scalar
     * (e.g. a quoted `cache.enabled: "false"`) is silently ignored by the
     * downstream resolvers and falls back to a default.
     *
     * A `~` / null value is a valid "use the default" idiom and is not checked.
     *
     * @param array<string, mixed> $config Post-normalization config (camelCase keys)
     */
    private function validateScalarTypes(array $config, string $path): void
    {
        foreach (ConfigSchema::ENTRIES as [$sourcePath, $resultKey, , $scalarType]) {
            if ($scalarType === null) {
                continue;
            }

            $value = self::resolveNormalizedValue($config, $sourcePath);
            if ($value === null) {
                continue;
            }

            if (ConfigSchema::matchesScalarType($value, $scalarType)) {
                continue;
            }

            throw ConfigurationRefusal::atConfigFileKey(
                $path,
                RefusedPosition::open(explode('.', $sourcePath), $resultKey),
                \sprintf(
                    'Invalid value for "%s": expected %s, got %s',
                    $resultKey,
                    $scalarType,
                    ConfigSchema::scalarTypeName($value),
                ),
            );
        }
    }

    /**
     * Resolves a camelCase source path from the post-normalization config map.
     *
     * @param array<string, mixed> $config
     */
    private static function resolveNormalizedValue(array $config, string $sourcePath): mixed
    {
        if (str_contains($sourcePath, '.')) {
            [$section, $key] = explode('.', $sourcePath, 2);

            return \is_array($config[$section] ?? null) ? ($config[$section][$key] ?? null) : null;
        }

        return $config[$sourcePath] ?? null;
    }

    /**
     * Finds the original (pre-normalization) section name from raw config.
     *
     * @param array<string, mixed> $rawConfig
     */
    private function findOriginalSectionName(string $normalizedSection, array $rawConfig): string
    {
        foreach (array_keys($rawConfig) as $originalKey) {
            if (ConfigKeySpelling::normalize((string) $originalKey) === $normalizedSection) {
                return (string) $originalKey;
            }
        }

        return $normalizedSection;
    }

    /**
     * Finds the original (pre-normalization) sub-key name from raw config.
     *
     * @param array<string, mixed> $rawConfig
     */
    private function findOriginalSubKey(string $normalizedSection, string $normalizedSubKey, array $rawConfig): string
    {
        // Find the original section first
        foreach ($rawConfig as $originalSection => $value) {
            if (ConfigKeySpelling::normalize((string) $originalSection) !== $normalizedSection || !\is_array($value)) {
                continue;
            }

            foreach (array_keys($value) as $originalSubKey) {
                if (ConfigKeySpelling::normalize((string) $originalSubKey) === $normalizedSubKey) {
                    return (string) $originalSubKey;
                }
            }
        }

        return $normalizedSubKey;
    }

    /**
     * Normalized keys rewritten in the separator style of what the author
     * wrote, so a suggestion or a list of allowed keys answers in their own
     * spelling rather than in one they never typed.
     *
     * @param list<string> $normalizedKeys
     *
     * @return list<string>
     */
    private static function spelledLike(array $normalizedKeys, string $authored): array
    {
        return array_map(
            static fn(string $key): string => ConfigKeySpelling::offerLike($key, $authored),
            $normalizedKeys,
        );
    }

    /**
     * Suggests the closest matching key using Levenshtein distance.
     *
     * @param list<string> $allowed
     */
    private static function suggestSimilarKey(string $unknown, array $allowed): ?string
    {
        $bestMatch = null;
        $bestDistance = \PHP_INT_MAX;
        $maxDistance = 3;

        foreach ($allowed as $candidate) {
            $distance = levenshtein(strtolower($unknown), strtolower($candidate));
            if ($distance < $bestDistance && $distance <= $maxDistance) {
                $bestDistance = $distance;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }
}
