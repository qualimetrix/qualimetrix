<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Exclusion;

/**
 * The suppression a producer's own `rules:` section configures, read off the
 * raw options array — every option, under every spelling.
 *
 * **It exists because the set of options was enumerated twice.**
 * {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger} applies three:
 * `suppress_paths`, `suppress_namespaces` and `suppress_namespace_channels`.
 * The channel that reports a suppression value binding to nothing
 * ({@see \Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit})
 * re-derived the list and reached two, so a pattern under the third option
 * stayed in exactly the silence that channel exists to end. An enumeration
 * taken separately from the thing it enumerates drifts without anyone seeing
 * it; asking one reader is what makes "applied" and "judged" the same set by
 * construction rather than by intention.
 *
 * Each option accepts two spellings — the snake_case an author writes in
 * `qmx.yaml`, and the camelCase
 * {@see \Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface::all()}
 * returns once the configuration pipeline's section-normalization policy has
 * run. Reading is deliberately lenient: malformed configuration is refused
 * where it is parsed ({@see RuleNamespaceExclusionProvider}), and a reader that
 * threw here would turn a report into a crash for a value the parser has
 * already judged.
 */
final readonly class ConfiguredSuppression
{
    /**
     * The options, spelled as an author writes them. These three names are the
     * enumeration both consumers share: a fourth option is added here, and
     * both sides gain it in the same edit.
     */
    public const string PATHS = 'suppress_paths';
    public const string NAMESPACES = 'suppress_namespaces';
    public const string NAMESPACE_CHANNELS = 'suppress_namespace_channels';

    /**
     * `suppress_paths` patterns.
     *
     * @param array<mixed> $options one producer's raw options
     *
     * @return list<string>
     */
    public static function paths(array $options): array
    {
        return self::patternsOf($options['suppressPaths'] ?? $options[self::PATHS] ?? []);
    }

    /**
     * `suppress_namespaces` patterns.
     *
     * @param array<mixed> $options one producer's raw options
     *
     * @return list<string>
     */
    public static function namespaces(array $options): array
    {
        return self::patternsOf($options['suppressNamespaces'] ?? $options[self::NAMESPACES] ?? []);
    }

    /**
     * The `suppress_namespace_channels` map as configured: channel selector to
     * its raw patterns. The selector is left uninterpreted — deciding whether
     * it addresses a given channel is
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector}'s
     * job, and only the applying side has a channel to ask about.
     *
     * @param array<mixed> $options one producer's raw options
     *
     * @return array<string, mixed>
     */
    public static function rawNamespaceChannels(array $options): array
    {
        $channels = $options['suppressNamespaceChannels'] ?? $options[self::NAMESPACE_CHANNELS] ?? [];

        if (!\is_array($channels)) {
            return [];
        }

        $map = [];
        foreach ($channels as $selector => $patterns) {
            if (\is_string($selector)) {
                $map[$selector] = $patterns;
            }
        }

        return $map;
    }

    /**
     * Every `suppress_namespace_channels` pattern, paired with the selector it
     * was written under — the unit a report about one value names, since two
     * selectors may carry the same pattern for different channels.
     *
     * @param array<mixed> $options one producer's raw options
     *
     * @return list<array{selector: string, pattern: string}>
     */
    public static function namespaceChannelPatterns(array $options): array
    {
        $pairs = [];

        foreach (self::rawNamespaceChannels($options) as $selector => $patterns) {
            foreach (self::patternsOf($patterns) as $pattern) {
                $pairs[] = ['selector' => $selector, 'pattern' => $pattern];
            }
        }

        return $pairs;
    }

    /**
     * A raw option value as a list of patterns: a bare string is one pattern,
     * a list keeps its strings, anything else is no pattern at all.
     *
     * Public because the applying side reads one selector's patterns out of
     * the channel map it has already matched, and must read them the same way.
     *
     * @return list<string>
     */
    public static function patternsOf(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $entry) {
            if (\is_string($entry)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }
}
