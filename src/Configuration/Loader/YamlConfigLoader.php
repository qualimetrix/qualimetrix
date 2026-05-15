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
            $map[$this->snakeToCamel((string) $originalKey)] = (string) $originalKey;
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
     * The {@code architecture.allow} subtree is currently preserved via
     * {@see ConfigSchema::nestedIdentifierKeyPaths()} during the Phase 3
     * transition. Phase 3.5 migrates {@code architecture} to
     * {@code PRESERVE_SUBTREE}, after which the sub-path opt-out is empty
     * and Phase 3.6 removes the wrapper entirely.
     *
     * See [ADR 0009](../../../docs/adr/0009-yaml-loader-normalization-model.md).
     *
     * @param array<string|int, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function normalizeKeys(array $config): array
    {
        $nestedPaths = ConfigSchema::nestedIdentifierKeyPaths();
        $result = [];

        foreach ($config as $key => $value) {
            $stringKey = (string) $key;
            $normalizedRoot = $this->snakeToCamel($stringKey);

            // Lookup policy by normalized root key; unregistered roots throw
            // LogicException via policyFor() — but only roots from
            // allowedRootKeys() are reachable here, because validateRootKeys()
            // runs later. For unknown roots (validation will reject them next),
            // default to NORMALIZE_TO_CAMEL_CASE so we still produce a usable
            // shape for the error path.
            $policy = isset(ConfigSchema::sectionPolicies()[$normalizedRoot])
                ? ConfigSchema::policyFor($normalizedRoot)
                : SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE;

            $result[$normalizedRoot] = \is_array($value)
                ? $this->applyPolicy($value, $policy, $normalizedRoot, $nestedPaths)
                : $value;
        }

        return $result;
    }

    /**
     * Walks a sub-tree starting at {@code $currentPath} applying the section
     * {@code $policy}. The {@code $nestedPreservePaths} list keeps the
     * {@code architecture.allow} sub-path opt-out alive during the Phase 3
     * transition; any path equal to or descended from one becomes implicitly
     * {@code PRESERVE_SUBTREE} regardless of the section policy.
     *
     * @param array<string|int, mixed> $config
     * @param list<string> $nestedPreservePaths
     *
     * @return array<string|int, mixed>
     */
    private function applyPolicy(
        array $config,
        SectionNormalizationPolicy $policy,
        string $currentPath,
        array $nestedPreservePaths,
    ): array {
        // Determine effective preservation at THIS level. The depth-1
        // preservation for PRESERVE_IMMEDIATE_CHILDREN is decided by
        // looking at whether $currentPath equals the section root (no dot
        // in it). PRESERVE_SUBTREE preserves at every depth.
        $effectivelyPreserveSubtree = $policy === SectionNormalizationPolicy::PRESERVE_SUBTREE
            || self::isUnderPreservedPath($currentPath, $nestedPreservePaths);

        $preserveImmediateLevel = $policy === SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN
            && !str_contains($currentPath, '.');

        $preserveKeysHere = $effectivelyPreserveSubtree || $preserveImmediateLevel;

        $result = [];

        foreach ($config as $key => $value) {
            $stringKey = (string) $key;

            // List items (integer keys) carry no user-facing snake_case;
            // the integer-as-string-key passes through both branches
            // unchanged. Preserve the original key type so list shape is
            // retained.
            if (\is_int($key)) {
                $newKey = $key;
            } else {
                $newKey = $preserveKeysHere ? $stringKey : $this->snakeToCamel($stringKey);
            }

            if (!\is_array($value)) {
                $result[$newKey] = $value;

                continue;
            }

            $childPath = $currentPath . '.' . (string) $newKey;

            // For PRESERVE_SUBTREE (and the nested-path opt-out), descent
            // keeps preserving forever. For PRESERVE_IMMEDIATE_CHILDREN,
            // once we descend below the section root we resume normalization.
            // For NORMALIZE_TO_CAMEL_CASE, no level preserves.
            $childPolicy = $effectivelyPreserveSubtree
                ? SectionNormalizationPolicy::PRESERVE_SUBTREE
                : SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE;

            $result[$newKey] = $this->applyPolicy($value, $childPolicy, $childPath, $nestedPreservePaths);
        }

        return $result;
    }

    /**
     * Returns true when {@code $path} equals one of {@code $preservedPaths} or
     * lies strictly below one of them. Used by the Phase 3 transition to keep
     * the {@code architecture.allow} sub-path opt-out alive
     * ({@see ConfigSchema::nestedIdentifierKeyPaths()}) until Phase 3.5
     * promotes {@code architecture} to {@code PRESERVE_SUBTREE}.
     *
     * @param list<string> $preservedPaths
     */
    private static function isUnderPreservedPath(string $path, array $preservedPaths): bool
    {
        foreach ($preservedPaths as $preservedPath) {
            if ($path === $preservedPath || str_starts_with($path, $preservedPath . '.')) {
                return true;
            }
        }

        return false;
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
     * @param array<string, mixed> $rawConfig Pre-normalization config for sub-key error messages
     */
    private function validateStructure(array $config, string $path, array $keyMap, array $rawConfig): void
    {
        $this->validateRootKeys($config, $path, $keyMap);
        $this->validateRulesSection($config, $path, $keyMap);
        $this->validateTypeConstraints($config, $path, $keyMap);
        $this->validateSectionSubKeys($config, $path, $rawConfig);
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

        throw ConfigLoadException::invalidStructure(
            $path,
            \sprintf('Unknown configuration %s: %s', \count($messages) === 1 ? 'key' : 'keys', implode(', ', $messages)),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     */
    private function validateRulesSection(array $config, string $path, array $keyMap): void
    {
        if (!isset($config[ConfigSchema::RULES])) {
            return;
        }

        if (!\is_array($config[ConfigSchema::RULES])) {
            throw ConfigLoadException::invalidStructure(
                $path,
                \sprintf('"%s" must be an associative array', $this->originalKey(ConfigSchema::RULES, $keyMap)),
            );
        }

        foreach ($config[ConfigSchema::RULES] as $ruleName => $ruleConfig) {
            if (!\is_array($ruleConfig) && !\is_bool($ruleConfig) && $ruleConfig !== null) {
                throw ConfigLoadException::invalidStructure(
                    $path,
                    \sprintf('Rule "%s" configuration must be an array, boolean, or null', $ruleName),
                );
            }
        }
    }

    /**
     * Validates that section keys are arrays and list keys are arrays.
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
                throw ConfigLoadException::invalidStructure(
                    $path,
                    \sprintf('"%s" must be an associative array', $this->originalKey($section, $keyMap)),
                );
            }
        }

        foreach (ConfigSchema::listKeys() as $field) {
            if (isset($config[$field]) && !\is_array($config[$field])) {
                throw ConfigLoadException::invalidStructure(
                    $path,
                    \sprintf('"%s" must be a list', $this->originalKey($field, $keyMap)),
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

            throw ConfigLoadException::invalidStructure(
                $path,
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
     * Finds the original (pre-normalization) section name from raw config.
     *
     * @param array<string, mixed> $rawConfig
     */
    private function findOriginalSectionName(string $normalizedSection, array $rawConfig): string
    {
        foreach (array_keys($rawConfig) as $originalKey) {
            if ($this->snakeToCamel((string) $originalKey) === $normalizedSection) {
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
            if ($this->snakeToCamel((string) $originalSection) !== $normalizedSection || !\is_array($value)) {
                continue;
            }

            foreach (array_keys($value) as $originalSubKey) {
                if ($this->snakeToCamel((string) $originalSubKey) === $normalizedSubKey) {
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
