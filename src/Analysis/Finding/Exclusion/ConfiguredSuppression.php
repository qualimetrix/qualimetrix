<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Exclusion;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;

/**
 * Canonical spellings for the three per-rule suppression options.
 *
 * Runtime values are decoded once by `RuleOptionsFactory` and exposed through
 * typed `RuleConfigurationInterface` methods. The only remaining raw read is
 * the channel-map shape needed by Console validation before rule options are
 * constructed.
 */
final readonly class ConfiguredSuppression
{
    /**
     * Snake-case authored spellings also used by unmatched-suppression
     * diagnostics. `assertSpellingsMatchTheOwner()` keeps this enumeration in
     * sync with `FrameworkOptionKeys`.
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

}
