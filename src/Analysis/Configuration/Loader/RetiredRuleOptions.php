<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Core\Util\ConfigKeySpelling;

/**
 * Refuses a retired `exclude*` option written inside a `rules:` block, naming
 * it the way its author wrote it.
 *
 * Reads the section **before** normalization, and that is the whole reason it
 * is here rather than in the rule layer, which owns this vocabulary otherwise:
 * the loader is the last place the authored spelling exists. Below it
 * `exclude_paths`, `exclude-paths` and `excludePaths` are one key, so a refusal
 * raised later can only answer in a spelling its author may never have typed —
 * the state this class was written to end.
 *
 * The rule layer keeps its own copy of the same family for the `--rule-opt`
 * door, which the loader never sees;
 * `RetiredRuleOptionKeysAgreementTest` pins the two together so a rename
 * taught to one door cannot stay unknown to the other.
 */
final class RetiredRuleOptions
{
    /**
     * @var array<string, string> retired option => its replacement, both normalized;
     *                            the authored spelling is restored per refusal
     */
    private const array RETIRED_KEYS = [
        'excludePaths' => 'suppressPaths',
        'excludeNamespaces' => 'suppressNamespaces',
        'excludeNamespaceChannels' => 'suppressNamespaceChannels',
    ];

    /**
     * Finds the `rules:` block itself rather than being handed one: reading a
     * raw document is this class's business, and the loader's own structural
     * checks stay free of the branches that come with it.
     *
     * @param array<array-key, mixed> $rawConfig the document exactly as written
     * @param string $rulesKey the spelling its author gave the `rules` root
     */
    public static function refuseIn(array $rawConfig, string $rulesKey, string $path): void
    {
        $section = $rawConfig[$rulesKey] ?? null;

        if (!\is_array($section)) {
            return;
        }

        foreach ($section as $ruleName => $ruleConfig) {
            if (!\is_array($ruleConfig)) {
                continue;
            }

            self::refuseOne((string) $ruleName, array_keys($ruleConfig), $path);
        }
    }

    /**
     * The retired families, for the test pinning them against the rule layer's
     * copy.
     *
     * @return array<string, string>
     */
    public static function retiredKeys(): array
    {
        return self::RETIRED_KEYS;
    }

    /** @param list<array-key> $authoredOptions */
    private static function refuseOne(string $ruleName, array $authoredOptions, string $path): void
    {
        foreach ($authoredOptions as $authored) {
            $replacement = self::RETIRED_KEYS[ConfigKeySpelling::normalize((string) $authored)] ?? null;

            if ($replacement === null) {
                continue;
            }

            throw ConfigLoadException::invalidStructure($path, \sprintf(
                'Rule "%s" uses the retired option "%s". To suppress findings this rule already produces, '
                . 'use "%s". To exclude files from analysis entirely (the finding is never produced), use the '
                . 'top-level "exclude" option instead — it is a different mechanism, not a renamed one.',
                $ruleName,
                $authored,
                ConfigKeySpelling::rewriteLike($replacement, (string) $authored),
            ));
        }
    }
}
