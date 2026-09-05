<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;

/**
 * The retired `exclude*` suppression spellings, and the one refusal that names
 * what replaced them.
 *
 * Four doors accept a configuration key — the YAML root, a `rules:` block, the
 * `--rule-opt` flag and the rule-option factory behind it — and each of them
 * used to carry its own copy of this family and of the sentence refusing it.
 * The copies had already drifted apart in wording. One text, named once, is
 * what keeps a rename taught to one door from staying unknown to the others;
 * nothing else was holding them together but a test comparing two of the maps.
 *
 * Configuration owns it because the rule layer already imports Configuration
 * and the reverse edge would be a cycle. That constraint decides the direction,
 * not the subject: a configuration key's standing is Configuration's business
 * either way.
 *
 * A refusal answers in the spelling its author wrote. Below the loader
 * `exclude_paths`, `exclude-paths` and `excludePaths` are one key, so the
 * doors that still hold the authored spelling are the only ones that can.
 */
final class RetiredSuppressionOptions
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
     * Refuses a retired option written inside a `rules:` block.
     *
     * Finds the block itself rather than being handed one: reading a raw
     * document is this refusal's business, and the loader's own structural
     * checks stay free of the branches that come with it.
     *
     * @param array<array-key, mixed> $rawConfig the document exactly as written
     * @param string $rulesKey the spelling its author gave the `rules` root
     */
    public static function refuseInRules(array $rawConfig, string $rulesKey, string $path): void
    {
        $section = $rawConfig[$rulesKey] ?? null;

        if (!\is_array($section)) {
            return;
        }

        foreach ($section as $ruleConfig) {
            if (!\is_array($ruleConfig)) {
                continue;
            }

            foreach (array_keys($ruleConfig) as $authored) {
                $refusal = self::refusalFor((string) $authored);

                if ($refusal !== null) {
                    throw ConfigLoadException::invalidStructure($path, $refusal);
                }
            }
        }
    }

    /**
     * Refuses a retired root-level spelling instead of letting it fall through
     * as a generic "unknown configuration key".
     *
     * The generic message suggests only a *similarly spelled* allowed key
     * (Levenshtein distance), and `exclude_paths` is not close enough to
     * `suppress_paths` for that heuristic to find the rename on its own — a
     * silently-refused root key would stop suppressing findings without saying
     * why.
     *
     * Only families whose replacement is itself a live root key are refused
     * here: a per-rule-only option written at the root is not a rename anyone
     * can act on, and answering it with a root spelling that does not exist
     * would trade one wrong message for another.
     *
     * @param array<int, string> $unknownKeys normalized (camelCase) key names
     * @param array<string, string> $keyMap normalized key => the spelling the author wrote
     */
    public static function refuseRootKey(array $unknownKeys, string $path, array $keyMap): void
    {
        $liveRootKeys = ConfigSchema::allowedRootKeys();

        foreach ($unknownKeys as $key) {
            $replacement = self::REPLACEMENTS[$key] ?? null;

            if ($replacement === null || !\in_array($replacement, $liveRootKeys, true)) {
                continue;
            }

            $authored = $keyMap[$key] ?? $key;

            throw ConfigLoadException::invalidStructure(
                $path,
                self::refusalText($authored, ConfigKeySpelling::rewriteLike($replacement, $authored)),
            );
        }
    }

    /**
     * Refuses a retired per-rule option spelling instead of letting it fall
     * through to the unknown-key path as a mere warning.
     *
     * A silently-ignored key would keep the rule running while its suppression
     * config stopped applying — findings the user configured away would
     * resurface with nothing but a swallowed log line to explain it (measured:
     * the unknown-key path is a `warning`, not a refusal).
     *
     * Called at the `--rule-opt` door before the option name is normalized,
     * while the spelling is still the author's. The call from the options
     * factory stays behind it as the backstop for options assembled in code
     * rather than typed by a user; the keys it sees are already normalized, and
     * its message says so by printing them.
     *
     * @param array<array-key, mixed> $userConfig option keys as the caller received them
     */
    public static function refuseRuleOption(array $userConfig): void
    {
        foreach (array_keys($userConfig) as $authored) {
            $refusal = self::refusalFor((string) $authored);

            if ($refusal !== null) {
                throw new InvalidArgumentException($refusal);
            }
        }
    }

    /**
     * The one refusal text, for the command-line door — the only one whose
     * retired names (`--exclude-path`, singular) are not configuration keys and
     * so cannot be looked up in the map above.
     *
     * The fork follows the spelling the author used: a reader who typed a flag
     * is told about `--exclude`, a reader who wrote a key about `exclude`. The
     * two are the same mechanism, and neither is the rename.
     */
    public static function refusalText(string $authored, string $replacement): string
    {
        return \sprintf(
            'The "%s" option was retired. To suppress findings the analysis already produces, use "%s". '
            . 'To exclude files from analysis entirely (the finding is never produced), use the "%s" option '
            . 'instead — it is a different mechanism, not a renamed one.',
            $authored,
            $replacement,
            str_starts_with($authored, '--') ? '--exclude' : 'exclude',
        );
    }

    /** @return string|null null when `$authored` names no retired option */
    private static function refusalFor(string $authored): ?string
    {
        $replacement = self::REPLACEMENTS[ConfigKeySpelling::normalize($authored)] ?? null;

        return $replacement === null
            ? null
            : self::refusalText($authored, ConfigKeySpelling::rewriteLike($replacement, $authored));
    }
}
