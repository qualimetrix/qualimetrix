<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;

/**
 * Validates rule names in configuration data against registered rules.
 *
 * Shared by ConfigFileStage and PresetStage to throw a hard error about unknown rule names
 * before they silently get ignored at runtime.
 */
final class RuleNameValidator
{
    private const int MAX_LEVENSHTEIN_DISTANCE = 3;

    /**
     * Validates rule names in the "rules:" config section against registered rules.
     *
     * Throws ConfigLoadException if any rule name does not match a registered rule.
     * Matching follows prefix logic: exact match, forward prefix ("complexity" matches
     * "complexity.cyclomatic"), and reverse prefix ("complexity.cyclomatic.method" refines
     * "complexity.cyclomatic").
     *
     * For each unknown name, suggests the closest known rule name via Levenshtein distance
     * (max distance 3).
     *
     * @param array<string, mixed> $data config data (post YAML loading, rule name keys preserved as-is)
     * @param string $configSource label for error messages (e.g., "preset:strict", "qmx.yaml")
     * @param string $configPath path to config file for error messages
     *
     * @throws ConfigLoadException if unknown rule names are found
     */
    public static function validateRuleNames(
        array $data,
        string $configSource,
        KnownRuleNamesProviderInterface $provider,
        string $configPath,
    ): void {
        $rulesSection = $data[ConfigSchema::RULES] ?? null;
        if (!\is_array($rulesSection) || $rulesSection === []) {
            return;
        }

        $knownNames = $provider->getKnownRuleNames();
        $unknowns = [];

        foreach (array_keys($rulesSection) as $configuredName) {
            $name = (string) $configuredName;
            $matched = false;
            foreach ($knownNames as $known) {
                if ($name === $known
                    || str_starts_with($known, $name . '.')
                    || str_starts_with($name, $known . '.')
                ) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $unknowns[] = $name;
            }
        }

        if ($unknowns === []) {
            return;
        }

        $messages = [];
        foreach ($unknowns as $unknown) {
            $suggestion = self::findClosestMatch($unknown, $knownNames);
            $line = \sprintf('Unknown rule "%s" in %s', $unknown, $configSource);
            if ($suggestion !== null) {
                $line .= \sprintf('. Did you mean "%s"?', $suggestion);
            }
            $messages[] = $line;
        }

        throw ConfigLoadException::invalidStructure(
            $configPath,
            implode("\n", $messages),
        );
    }

    /**
     * Finds the closest known rule name via Levenshtein distance.
     *
     * @param list<string> $knownNames
     */
    private static function findClosestMatch(string $unknown, array $knownNames): ?string
    {
        $bestMatch = null;
        $bestDistance = self::MAX_LEVENSHTEIN_DISTANCE + 1;

        foreach ($knownNames as $known) {
            $distance = levenshtein($unknown, $known);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $known;
            }
        }

        return $bestMatch;
    }
}
