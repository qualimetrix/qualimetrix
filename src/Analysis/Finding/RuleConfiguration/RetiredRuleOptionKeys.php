<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use Qualimetrix\Core\Util\ConfigKeySpelling;

/**
 * The per-rule option spellings that were retired, and the refusal that names
 * what replaced each of them.
 *
 * Separate from {@see RuleOptionsFactory} because it answers a different
 * question: the factory decides what an Options object is built from, this
 * decides what a reader who wrote yesterday's spelling meant. A rename touches
 * only the map below.
 */
final class RetiredRuleOptionKeys
{
    /**
     * @var array<string, string> retired option => its replacement, both normalized;
     *                            the authored spelling is restored per refusal
     */
    private const array REPLACEMENTS = [
        'excludeNamespaceChannels' => 'suppressNamespaceChannels',
        'excludeNamespaces' => 'suppressNamespaces',
        'excludePaths' => 'suppressPaths',
    ];

    /**
     * Refuses a retired `exclude*` spelling instead of letting it fall through
     * to the unknown-key path as a mere warning.
     *
     * A silently-ignored key would keep the rule running while its suppression
     * config stopped applying — findings the user configured away would
     * resurface with nothing but a swallowed log line to explain it (measured:
     * the unknown-key path is a `warning`, not a refusal). The message names
     * both live spellings so a reader who meant to suppress findings on this
     * rule reaches `suppress_*`, and a reader who actually meant to exclude
     * files from analysis entirely reaches the root-level `exclude` — the two
     * were conflated under one word for three follow-up rounds running.
     *
     * Called at the `--rule-opt` door before the option name is normalized,
     * while the spelling is still the author's. The config-file door has its
     * own copy of this family in
     * {@see \Qualimetrix\Analysis\Configuration\Loader\RetiredRuleOptions}, because the
     * loader is the last place an authored YAML spelling exists and the two
     * capabilities may not import each other; the agreement test holds them
     * together. The call from {@see RuleOptionsFactory} stays behind both as
     * the backstop for options assembled in code rather than typed by a user;
     * the keys it sees are already normalized, and its message says so by
     * printing them.
     *
     * @param array<array-key, mixed> $userConfig option keys as the caller received them
     */
    public static function refuse(string $ruleName, array $userConfig): void
    {
        foreach (array_keys($userConfig) as $authored) {
            $replacement = self::REPLACEMENTS[ConfigKeySpelling::normalize((string) $authored)] ?? null;

            if ($replacement === null) {
                continue;
            }

            throw new InvalidArgumentException(\sprintf(
                'Rule "%s" uses the retired option "%s". To suppress findings this rule already produces, '
                . 'use "%s". To exclude files from analysis entirely (the finding is never produced), use the '
                . 'top-level "exclude" option instead — it is a different mechanism, not a renamed one.',
                $ruleName,
                $authored,
                ConfigKeySpelling::rewriteLike($replacement, (string) $authored),
            ));
        }
    }

    /**
     * The retired families, for the test pinning them against the config
     * loader's copy.
     *
     * @return array<string, string>
     */
    public static function replacements(): array
    {
        return self::REPLACEMENTS;
    }
}
