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

        $normalized = $this->normalizeKeys($content);

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
     * Normalizes the root-level config map using the per-section policy declared
     * in {@see ConfigSchema::sectionPolicies()}.
     *
     * Each root key chooses one of three policies (see
     * {@see SectionNormalizationPolicy}):
     *
     *  - {@code NORMALIZE_TO_CAMEL_CASE}: keys are camelCased at every depth.
     *  - {@code PRESERVE_IMMEDIATE_CHILDREN}: level-1 keys are preserved
     *    verbatim (user identifiers); level-2 and deeper resume normalization.
     *  - {@code PRESERVE_SUBTREE}: every descendant key is preserved verbatim,
     *    including scalar leaves at every depth — closes the leaf-mangling
     *    bug class the previous opt-out model could not address.
     *
     * See [ADR 0009](../../../docs/adr/0009-yaml-loader-normalization-model.md).
     *
     * @param array<string|int, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function normalizeKeys(array $config): array
    {
        $policies = ConfigSchema::sectionPolicies();
        $result = [];

        foreach ($config as $key => $value) {
            $stringKey = (string) $key;
            $normalizedRoot = ConfigKeySpelling::normalize($stringKey);

            // Unregistered roots will be rejected by validateRootKeys() below;
            // default them to NORMALIZE so we still produce a usable shape for
            // the error path without throwing LogicException prematurely.
            $policy = $policies[$normalizedRoot] ?? SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE;

            $result[$normalizedRoot] = \is_array($value)
                ? $this->applyPolicy($value, $policy, depth: 0)
                : $value;
        }

        return $result;
    }

    /**
     * Walks a sub-tree applying the section {@code $policy} according to
     * {@code $depth}:
     *
     *  - {@code PRESERVE_SUBTREE}: preserve at every depth.
     *  - {@code PRESERVE_IMMEDIATE_CHILDREN}: preserve at depth 0 (the
     *    section root's children); resume normalization at depth >= 1.
     *  - {@code NORMALIZE_TO_CAMEL_CASE}: never preserve.
     *
     * List items (integer keys) carry no user-facing snake_case; their keys
     * pass through unchanged regardless of policy.
     *
     * Where a sub-array goes next is the policy's own answer
     * ({@see SectionNormalizationPolicy::childPolicyFor()} and
     * {@see SectionNormalizationPolicy::childDepthFor()}): an option declared
     * identifier-keyed opens a fresh identifier boundary for its own children.
     *
     * @param array<string|int, mixed> $config
     *
     * @return array<string|int, mixed>
     */
    private function applyPolicy(array $config, SectionNormalizationPolicy $policy, int $depth): array
    {
        $preserveKeysHere = $policy === SectionNormalizationPolicy::PRESERVE_SUBTREE
            || ($policy === SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN && $depth === 0);

        $result = [];

        foreach ($config as $key => $value) {
            $normalizedKey = \is_int($key) ? $key : ConfigKeySpelling::normalize($key);
            $newKey = \is_int($key) || $preserveKeysHere ? $key : $normalizedKey;
            $result[$newKey] = \is_array($value)
                ? $this->applyPolicy($value, ...$policy->descentFor(
                    $normalizedKey,
                    ConfigSchema::identifierKeyedOptions(),
                    $depth,
                ))
                : $value;
        }

        return $result;
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
        $this->validateTypeConstraints($config, $path, $keyMap);
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

        // Build allowed keys in original format (snake_case) for suggestions
        $allowedOriginal = array_map(
            static fn(string $camelKey): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $camelKey)),
            $allowedRootKeys,
        );

        $messages = [];
        foreach ($unknownKeys as $key) {
            $original = $this->originalKey($key, $keyMap);
            $suggestion = self::suggestSimilarKey($original, $allowedOriginal);
            $messages[] = $suggestion !== null
                ? \sprintf('"%s" (did you mean "%s"?)', $original, $suggestion)
                : \sprintf('"%s"', $original);
        }

        $firstOriginal = $this->originalKey($unknownKeys[array_key_first($unknownKeys)], $keyMap);

        throw ConfigurationRefusal::atConfigFileKey(
            $path,
            RefusedPosition::closed([$firstOriginal], $firstOriginal, $allowedRootKeys),
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
     * Validates the container shape of every root: a section or map root must
     * not be a sequential list, a list root must not be a map.
     *
     * Shape is checked here rather than left to each root's owner because the
     * owner receives merged contributions and can name neither the document nor
     * the position, while a list under a section root used to reach
     * {@see validateSectionSubKeys()} and crash it with an integer sub-key.
     *
     * `[]` passes both directions: an empty container is a legitimate way to
     * write "nothing here" and its author has not chosen a shape.
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     */
    private function validateTypeConstraints(array $config, string $path, array $keyMap): void
    {
        foreach (ConfigSchema::associativeRootKeys() as $section) {
            if (!isset($config[$section])) {
                continue;
            }

            if (!\is_array($config[$section])) {
                $originalSection = $this->originalKey($section, $keyMap);

                throw ConfigurationRefusal::atConfigFileKey(
                    $path,
                    RefusedPosition::open([$originalSection], $originalSection),
                    \sprintf('"%s" must be an associative array', $originalSection),
                );
            }
        }

        foreach (ConfigSchema::allowedSectionSubKeys() as $section => $allowedSubKeys) {
            $value = $config[$section] ?? null;

            if (!\is_array($value) || $value === [] || !array_is_list($value)) {
                continue;
            }

            $originalSection = $this->originalKey($section, $keyMap);
            $originalSubKeys = array_map(
                static fn(string $camelKey): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $camelKey)),
                $allowedSubKeys,
            );

            throw ConfigurationRefusal::atConfigFileKey(
                $path,
                RefusedPosition::closed([$originalSection], $originalSection, $originalSubKeys),
                \sprintf(
                    'Invalid value for "%s": expected a section of named keys (%s), got a list.',
                    $originalSection,
                    implode(', ', $originalSubKeys),
                ),
            );
        }

        foreach (ConfigSchema::listKeys() as $field) {
            if (!isset($config[$field])) {
                continue;
            }

            $originalField = $this->originalKey($field, $keyMap);

            if (!\is_array($config[$field])) {
                throw ConfigurationRefusal::atConfigFileKey(
                    $path,
                    RefusedPosition::open([$originalField], $originalField),
                    \sprintf('"%s" must be a list', $originalField),
                );
            }

            if ($config[$field] !== [] && !array_is_list($config[$field])) {
                throw ConfigurationRefusal::atConfigFileKey(
                    $path,
                    RefusedPosition::open([$originalField], $originalField),
                    \sprintf('Invalid value for "%s": expected a list of entries, got a map.', $originalField),
                );
            }
        }
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
                // Suggest against original (snake_case) allowed keys for better UX
                $originalAllowed = $this->getOriginalSectionSubKeys($section, $rawConfig);
                $suggestion = self::suggestSimilarKey($originalSubKey, $originalAllowed);
                $messages[] = $suggestion !== null
                    ? \sprintf('"%s" (did you mean "%s"?)', $originalSubKey, $suggestion)
                    : \sprintf('"%s"', $originalSubKey);
            }

            $firstOriginalSubKey = $this->findOriginalSubKey($section, $unknownSubKeys[array_key_first($unknownSubKeys)], $rawConfig);

            throw ConfigurationRefusal::atConfigFileKey(
                $path,
                RefusedPosition::closed(
                    [$originalSection, $firstOriginalSubKey],
                    $firstOriginalSubKey,
                    $this->getOriginalSectionSubKeys($section, $rawConfig),
                ),
                \sprintf(
                    'Unknown %s in "%s" section: %s. Allowed keys: %s',
                    \count($messages) === 1 ? 'key' : 'keys',
                    $originalSection,
                    implode(', ', $messages),
                    implode(', ', $this->getOriginalSectionSubKeys($section, $rawConfig)),
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
     * Returns original (snake_case) sub-key names for a section from raw config,
     * falling back to allowed camelCase names from schema.
     *
     * @param array<string, mixed> $rawConfig
     *
     * @return list<string>
     */
    private function getOriginalSectionSubKeys(string $normalizedSection, array $rawConfig): array
    {
        $allowedSubKeys = ConfigSchema::allowedSectionSubKeys()[$normalizedSection] ?? [];

        // Reverse-map camelCase to snake_case by examining what the user would write
        return array_map(
            static fn(string $camelKey): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $camelKey)),
            $allowedSubKeys,
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
