<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;

use Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;

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
     * Throws ConfigLoadException if any key is not the **exact** name of a
     * registered rule. A `rules:` key owns an options object, and options are
     * applied by exact key — a group key such as `complexity` matched the old
     * prefix logic, passed validation, and then configured nothing, which is
     * the failure mode this exactness removes. Neither is `complexity.*`
     * accepted: there is no such thing as a group of options.
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

            if (!\in_array($name, $knownNames, true)) {
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
