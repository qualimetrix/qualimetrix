<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader implements ConfigLoaderInterface
{
    private const array SUPPORTED_EXTENSIONS = ['yaml', 'yml'];

    public function read(string $path): LoadedDocument
    {
        $parsed = $this->parse($path);

        try {
            return new LoadedDocument(AuthoredNode::fromPlain($parsed), $this->normalize($parsed, $path));
        } catch (ConfigurationRefusal $refusal) {
            return new LoadedDocument(AuthoredNode::fromPlain($parsed), [], $refusal);
        }
    }

    /**
     * The document as its author wrote it: refused only as a whole (missing,
     * unreadable, unparseable, not a mapping) or for a retired root spelling,
     * whose own sentence would otherwise be lost to the engine's unknown-key
     * refusal.
     *
     * @throws ConfigurationRefusal
     *
     * @return array<string|int, mixed>
     */
    private function parse(string $path): array
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

        $keyMap = $this->buildRootKeyMap($content);
        RetiredSuppressionOptions::refuseRootKey(
            array_values(array_diff(array_keys($keyMap), ConfigSchema::allowedRootKeys())),
            $path,
            $keyMap,
        );

        return $content;
    }

    /**
     * Normalized layer values: keys are folded to one spelling, and the roots
     * the engine does not judge yet are checked here. Finding temporarily reads
     * its raw `rules` inputs; a root the engine judges passes these checks
     * whenever the engine accepted it.
     *
     * @param array<string|int, mixed> $parsed
     *
     * @throws ConfigurationRefusal
     *
     * @return array<string, mixed>
     */
    private function normalize(array $parsed, string $path): array
    {
        // Build reverse map (normalizedKey → originalKey) for user-facing error messages
        $keyMap = $this->buildRootKeyMap($parsed);

        $normalized = DocumentKeyNormalizer::normalize($parsed, $path);

        $this->validateRulesSection($normalized, $path, $keyMap, $parsed);
        RootContainerShapes::refuseWrongContainer($normalized, $path, $keyMap);
        $this->validateSectionSubKeys($normalized, $path, $parsed);

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
     * @param array<string|int, mixed> $config Raw YAML config
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
     * The raw section is handed to {@see RetiredSuppressionOptions} rather than the
     * normalized one: the three spellings of an option key have already
     * collapsed into one by then, and that refusal exists to answer in the
     * author's own.
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     * @param array<string|int, mixed> $rawConfig
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
     * @param array<string|int, mixed> $rawConfig Pre-normalization config for original key names
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
     * Finds the original (pre-normalization) section name from raw config.
     *
     * @param array<string|int, mixed> $rawConfig
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
     * @param array<string|int, mixed> $rawConfig
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
