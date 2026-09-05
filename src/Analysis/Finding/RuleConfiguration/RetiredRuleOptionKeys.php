<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;

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
    /** @var array<string, string> retired key => the current spelling, in both cases the config accepts */
    private const array REPLACEMENTS = [
        'exclude_namespace_channels' => 'suppress_namespace_channels',
        'excludeNamespaceChannels' => 'suppressNamespaceChannels',
        'exclude_namespaces' => 'suppress_namespaces',
        'excludeNamespaces' => 'suppressNamespaces',
        'exclude_paths' => 'suppress_paths',
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
     * @param array<string, mixed> $userConfig
     */
    public static function refuse(string $ruleName, array $userConfig): void
    {
        foreach (self::REPLACEMENTS as $retired => $current) {
            if (!\array_key_exists($retired, $userConfig)) {
                continue;
            }

            throw new InvalidArgumentException(\sprintf(
                'Rule "%s" uses the retired option "%s". To suppress findings this rule already produces, '
                . 'use "%s". To exclude files from analysis entirely (the finding is never produced), use the '
                . 'top-level "exclude" option instead — it is a different mechanism, not a renamed one.',
                $ruleName,
                $retired,
                $current,
            ));
        }
    }
}
