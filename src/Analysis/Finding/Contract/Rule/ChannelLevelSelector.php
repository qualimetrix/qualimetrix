<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Stringable;

/**
 * A user-authored selector over channels, optionally narrowed to one level of
 * the aggregation tree: `channel`, `channel.*`, `channel:level`,
 * `channel.*:level`.
 *
 * The level used to be a segment of the channel's own name
 * (`coupling.cbo.class`), which made it look like a name and behave like one:
 * `coupling.cbo.*` swept it up, and the level was written twice — once in the
 * name and once in the subject every finding already carries. It is a
 * **coordinate beside the name** now, and this type is the whole grammar for
 * writing that pair down.
 *
 * The separator is `:` rather than another dot for one reason: a dot is what
 * separates the segments of a name, so any dotted spelling of a level is a
 * spelling a producer could also have chosen for a channel. The colon cannot
 * appear in a name at all ({@see NameSelector} refuses it), so `X:class`
 * decomposes exactly one way.
 *
 * The level half is closed by {@see SymbolLevel}: `callable`, `class`, `file`,
 * `namespace`, `project`, and nothing else. Text outside that vocabulary is
 * not a selector, which is what lets a mistyped level be *refused* rather than
 * quietly addressing nothing.
 *
 * Whether the pair can exist — whether the addressed channel says it reports
 * at that level — is a different question, answered against the run's channel
 * universe in exactly one place: {@see ChannelLevelAddressing}.
 */
final readonly class ChannelLevelSelector implements Stringable
{
    /**
     * One declaration of the character, so the surfaces that must *refuse* a
     * malformed pair cannot drift apart from the surfaces that accept a
     * well-formed one. It is owned by {@see FindingChannel}, the type that
     * says what a channel name is: the character matters first as one a name
     * may never carry.
     */
    public const string LEVEL_SEPARATOR = FindingChannel::LEVEL_SEPARATOR;

    private function __construct(
        private NameSelector $channel,
        private ?SymbolLevel $level,
    ) {}

    /**
     * Parses selector text, or answers `null` when the text is neither a
     * channel selector nor a channel selector narrowed to a level.
     */
    public static function tryParse(string $raw): ?self
    {
        [$name, $level] = self::split($raw);

        if ($level === null && self::carriesLevelSeparator($raw)) {
            return null;
        }

        $channel = NameSelector::tryParse($name);

        return $channel === null ? null : new self($channel, $level);
    }

    /** Whether authored text is written as a pair at all. */
    public static function carriesLevelSeparator(string $raw): bool
    {
        return str_contains($raw, self::LEVEL_SEPARATOR);
    }

    /**
     * The level half of authored pair text, or `null` when the text carries
     * no separator or the half after it is not a level.
     *
     * Kept as a question of its own so a refusal can say *which* half was
     * wrong instead of calling the whole string unparseable.
     */
    public static function levelHalf(string $raw): ?SymbolLevel
    {
        return self::split($raw)[1];
    }

    /** The text before the separator — the channel half, whether or not it parses. */
    public static function channelHalf(string $raw): string
    {
        return self::split($raw)[0];
    }

    /**
     * The text after the separator, whether or not it is a level, or `null`
     * when there is no separator.
     *
     * Kept here rather than left to each refusal because a caller writing its
     * own `substr` picks its own separator occurrence: the two halves have to
     * come from the same split, or a refusal can quote a channel half and a
     * level half that do not add up to the text the author wrote.
     */
    public static function levelHalfText(string $raw): ?string
    {
        $separator = strrpos($raw, self::LEVEL_SEPARATOR);

        return $separator === false ? null : substr($raw, $separator + 1);
    }

    /** The channel half: an exact channel name, or `X.*` for its strict descendants. */
    public function channel(): NameSelector
    {
        return $this->channel;
    }

    /** The level this selector is narrowed to, or `null` when it addresses every level. */
    public function level(): ?SymbolLevel
    {
        return $this->level;
    }

    /**
     * Whether this selector addresses a finding on the given channel at the
     * given level. A selector carrying no level addresses every level of the
     * channels it names.
     */
    public function matches(string $code, ?SymbolLevel $level): bool
    {
        if (!$this->channel->matches($code)) {
            return false;
        }

        return $this->level === null || $this->level === $level;
    }

    public function __toString(): string
    {
        return $this->level === null
            ? (string) $this->channel
            : $this->channel . self::LEVEL_SEPARATOR . $this->level->value;
    }

    /**
     * @return array{string, ?SymbolLevel} the channel half, and the level the
     *                                     half after the separator names
     */
    private static function split(string $raw): array
    {
        $separator = strrpos($raw, self::LEVEL_SEPARATOR);

        if ($separator === false) {
            return [$raw, null];
        }

        return [substr($raw, 0, $separator), SymbolLevel::tryFrom(substr($raw, $separator + 1))];
    }
}
