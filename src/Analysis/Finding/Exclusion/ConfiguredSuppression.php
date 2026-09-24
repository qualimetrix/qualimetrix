<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Exclusion;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;

/**
 * Canonical spellings for the three per-rule suppression options.
 *
 * The one reader of a producer's raw suppression options, and the one place
 * their spellings are derived. `RuleOptionsFactory` takes the three values out
 * through {@see self::take()} and decodes them into typed
 * `RuleConfigurationInterface` state; console decoding reads them through the
 * `raw*()` methods before rule options are constructed.
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

    /** @param array<mixed> $options */
    public static function rawPaths(array $options): mixed
    {
        return self::rawValue($options, FrameworkOptionKeys::PATHS, self::PATHS);
    }

    /** @param array<mixed> $options */
    public static function rawNamespaces(array $options): mixed
    {
        return self::rawValue($options, FrameworkOptionKeys::NAMESPACES, self::NAMESPACES);
    }

    /**
     * Reads one suppression option's raw value and removes it from `$options`
     * under both spellings a door hands it over under, so that it does not
     * reach the producer's own options class as an unknown key.
     *
     * The value is returned exactly as written: deciding what it means, and
     * refusing a malformed one, belongs to the decoder the caller hands it to.
     *
     * @param array<string, mixed> $options
     *
     * @param-out array<string, mixed> $options
     *
     * @throws LogicException when `$canonicalKey` is not one of {@see FrameworkOptionKeys::all()}
     */
    public static function take(array &$options, string $canonicalKey): mixed
    {
        [$camelKey, $snakeKey] = self::spellingsOf($canonicalKey);
        $value = $options[$camelKey] ?? $options[$snakeKey] ?? null;

        unset($options[$camelKey], $options[$snakeKey]);

        return $value;
    }

    /** @param array<mixed> $options */
    private static function rawValue(array $options, string $canonicalKey, string $authoredKey): mixed
    {
        $camelKey = ConfigKeySpelling::normalize($canonicalKey);

        return $options[$camelKey] ?? $options[$authoredKey] ?? null;
    }

    /**
     * The camelCase spelling the configuration pipeline's normalization
     * produces, and the snake_case spelling an author writes in `qmx.yaml` —
     * both derived from the one canonical name.
     *
     * @return array{string, string}
     */
    private static function spellingsOf(string $canonicalKey): array
    {
        if (!\in_array($canonicalKey, FrameworkOptionKeys::all(), true)) {
            throw new LogicException(\sprintf('"%s" is not a suppression option.', $canonicalKey));
        }

        $camelKey = ConfigKeySpelling::normalize($canonicalKey);

        return [$camelKey, ConfigKeySpelling::rewriteLike($camelKey, 'a_b')];
    }

}
