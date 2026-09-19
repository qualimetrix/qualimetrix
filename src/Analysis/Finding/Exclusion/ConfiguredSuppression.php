<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Exclusion;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;

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
 * **"One reader" means every side that reads a producer's raw options — the
 * report included.**
 * {@see \Qualimetrix\Reporting\FindingProjection\RuleExclusionLedgerAttributor}
 * held a third copy of all six spellings while the claim here already said
 * there was one; the copy agreed by coincidence, and a guard listing the two
 * consumers it knew about could not see it. The guard is written against the
 * shape instead: a `suppress*` key subscripted off an options array anywhere
 * in `src/` — quoted or reached through the schema constant — is a reader, and
 * this is the only file allowed to be one.
 * Naming an option to *declare* or *validate* it — the config schema, the
 * parser, the CLI validators — is a different act and is untouched.
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
     * The options, spelled as an author writes them.
     *
     * The enumeration itself moved to {@see FrameworkOptionKeys}: the sides
     * that answer *about* these keys — the refusal listing what a rule allows,
     * and the `rules` command advertising it — cannot reach into this
     * namespace, and a copy kept for them was one of six.
     *
     * What stays here is spelling, and it stays as literals rather than being
     * derived, for a reason worth stating because the opposite reads as tidier:
     * the guard in `ConfiguredSuppressionTest` recognises a reader by a
     * suppression key written *at* a subscript, and a file that folded its
     * spellings out of a canonical name would subscript with a variable and
     * become invisible to the guard that keeps it the only reader. The snake
     * constants are also read by name elsewhere — `UnboundSuppressionAudit`
     * reports the option a dead pattern was written under — so they are a
     * published spelling, not an internal convenience.
     *
     * Adding a fourth option is therefore two edits, not one: the name in
     * `FrameworkOptionKeys`, and its pair of spellings here.
     * {@see self::assertSpellingsMatchTheOwner()} is what keeps the second from
     * being forgotten, and it judges the snake side — the camel twin written
     * inside each accessor is the guard's own anchor and is covered by the
     * round-trip test above, which reads each option under both spellings.
     */
    public const string PATHS = 'suppress_paths';
    public const string NAMESPACES = 'suppress_namespaces';
    public const string NAMESPACE_CHANNELS = 'suppress_namespace_channels';

    /**
     * Guards the sentence above: a constant here is the snake spelling of a key
     * {@see FrameworkOptionKeys} declares, and nothing else. A name that drifts
     * apart from the owner — or an option added to one side only — fails here
     * rather than in whichever consumer happens to read the stale half.
     */
    public static function assertSpellingsMatchTheOwner(): void
    {
        $authored = [self::PATHS, self::NAMESPACES, self::NAMESPACE_CHANNELS];
        sort($authored);

        $derived = array_map(
            static fn(string $key): string => ConfigKeySpelling::rewriteLike(
                ConfigKeySpelling::normalize($key),
                'a_b',
            ),
            FrameworkOptionKeys::all(),
        );
        sort($derived);

        if ($authored !== $derived) {
            throw new LogicException(\sprintf(
                'The suppression options spelled here (%s) are not the keys FrameworkOptionKeys declares (%s).',
                implode(', ', $authored),
                implode(', ', $derived),
            ));
        }
    }

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
