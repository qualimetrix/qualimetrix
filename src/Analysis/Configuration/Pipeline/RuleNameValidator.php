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
     * A rule rename typically changes the group prefix and keeps the leaf
     * name (`design.lcom` -> `cohesion.lcom`): raw character-edit distance
     * does not know that the leaf is the semantically stable half, so a
     * short unrelated name sharing the old prefix can score closer than the
     * renamed rule itself — `design.lcom` is 3 edits from `design.noc` but 4
     * from `cohesion.lcom`, so plain Levenshtein suggested the wrong one
     * after `cohesion.lcom` was renamed from `design.lcom` (see
     * `docs/internal/plans/sarif-channel-descriptions.md`, "Breaking",
     * ADR 0028). A known name whose leaf (the segment after the last `.`)
     * matches the unknown name's leaf exactly is preferred over one merely
     * closer by raw distance; ties are resolved by the existing distance
     * rule.
     *
     * @param list<string> $knownNames
     */
    private static function findClosestMatch(string $unknown, array $knownNames): ?string
    {
        $sameLeaf = self::matchingLeaf($unknown, $knownNames);

        if (\count($sameLeaf) === 1) {
            return $sameLeaf[0];
        }

        $candidates = $sameLeaf !== [] ? $sameLeaf : $knownNames;

        $bestMatch = null;
        $bestDistance = self::MAX_LEVENSHTEIN_DISTANCE + 1;

        foreach ($candidates as $known) {
            $distance = levenshtein($unknown, $known);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $known;
            }
        }

        return $bestMatch;
    }

    /**
     * @param list<string> $knownNames
     *
     * @return list<string> known names sharing the unknown name's last
     *                      dot-separated segment, or `[]` when the unknown
     *                      name carries no dot at all
     */
    private static function matchingLeaf(string $unknown, array $knownNames): array
    {
        $lastDot = strrpos($unknown, '.');

        if ($lastDot === false) {
            return [];
        }

        $suffix = '.' . substr($unknown, $lastDot + 1);

        return array_values(array_filter(
            $knownNames,
            static fn(string $known): bool => str_ends_with($known, $suffix),
        ));
    }
}
