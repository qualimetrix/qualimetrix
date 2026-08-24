<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The aggregation level of a finding, read out of the `subject` field.
 *
 * A case's `channels` claim is a claim about pairs, not names, and the level of
 * the pair is not published as a field of its own: it is carried by `subject`,
 * in the tag its canonical form starts with. That is the same fact the plan
 * stands on when it allows a channel map to collapse two level channels into
 * one — the findings stay distinguishable because `subject` still separates
 * them — so the claim reads the level from exactly the field the comparison
 * already guards. The map and the claim are different accountings: the unit of
 * *substitution* stays the name, and only the unit of *claim* becomes the pair.
 *
 * Why the claim needs it at all: the observed set used to be keyed by the channel
 * alone, so a channel firing at two levels inside one case was one observed
 * entry, and the claim it was compared against carried one line for it too. After
 * the level segment leaves the channel name, `complexity.cyclomatic` fires at
 * `callable` and at `class` in the same case, the fixture of one of those levels
 * can disappear, and every name-level check still passes: the channel fires, the
 * claim lists it once, the coverage union is unchanged. The corpus lives in the
 * case directory that both trees read, so no surface diff sees it either.
 *
 * The tags are a closed set on purpose. An unknown one is not defaulted to
 * anything: a subject shape the gate cannot level is a subject whose level the
 * claim would silently stop checking, so it stops the run instead. Measured
 * across the whole corpus on 2026-08-24: 194 findings, five shapes, all of them
 * below.
 */
final class SubjectLevel
{
    /** Separates the channel from its level in one claim entry. */
    public const SEPARATOR = '@';

    /**
     * The tag a subject's canonical form starts with => the level it means.
     *
     * `declaration:` is a prefix in front of the tag rather than a tag, so it is
     * stripped first: an exact declaration of a class and the logical class it
     * belongs to are the same level. The values are the product's own level
     * vocabulary (`SymbolLevel`), not the subject's spelling of it: `ns` is the
     * subject tag for the level the product and every case configuration call
     * `namespace`, and a global function is a callable rather than a level of its
     * own. Two artifacts about levels that spell them differently is a defect
     * class this repository has already paid for — so this is the gate's only
     * copy of that vocabulary, and it is held against the product's own enum
     * rather than asserted to match it:
     * {@see ChannelWitness::checkLevelVocabulary()}, run by every comparison and
     * by `--self-test`.
     *
     * @var array<string, string>
     */
    private const TAGS = [
        'callable' => 'callable',
        'class' => 'class',
        'func' => 'callable',
        'file' => 'file',
        'ns' => 'namespace',
        'project' => 'project',
    ];

    private const DECLARATION_PREFIX = 'declaration:';

    /** @return list<string> */
    public static function levels(): array
    {
        return array_values(array_unique(array_values(self::TAGS)));
    }

    /** The level of one finding's subject, or a stopped run. */
    public static function of(string $subject): string
    {
        $rest = str_starts_with($subject, self::DECLARATION_PREFIX)
            ? substr($subject, \strlen(self::DECLARATION_PREFIX))
            : $subject;
        $separator = strpos($rest, ':');
        $tag = $separator === false ? $rest : substr($rest, 0, $separator);

        return self::TAGS[$tag] ?? throw new GateError(\sprintf(
            'The subject "%s" starts with the tag "%s", which names no level the gate knows (%s). A claim is made of'
            . ' channel%slevel pairs, so a subject shape nothing can level is one whose level the claim would stop'
            . ' checking in silence: name the new shape here instead.',
            $subject,
            $tag,
            implode(', ', self::levels()),
            self::SEPARATOR,
        ));
    }

    /** One claim entry: the channel a finding names, and the level it fired at. */
    public static function claim(string $channel, string $level): string
    {
        return $channel . self::SEPARATOR . $level;
    }

    /**
     * Refuses a claim entry that is not a pair.
     *
     * A bare channel name is the shape the claim used to have, and it is refused
     * rather than read as "any level": a half-migrated `case.json` would
     * otherwise keep passing while claiming less than it looks like it claims.
     */
    public static function assertClaim(string $entry, string $file): void
    {
        $separator = strrpos($entry, self::SEPARATOR);
        $channel = $separator === false ? $entry : substr($entry, 0, $separator);
        $level = $separator === false ? '' : substr($entry, $separator + 1);

        if (!\in_array($level, self::levels(), true) || !str_contains($channel, '#')) {
            throw new GateError(\sprintf(
                '%s claims "%s", which is not a "rule#code%slevel" pair. The unit of a claim is the pair, because a'
                . ' channel firing at two levels in one case has to lose one of them visibly. Levels: %s.',
                $file,
                $entry,
                self::SEPARATOR,
                implode(', ', self::levels()),
            ));
        }
    }

    /** The channel half of a claim entry. */
    public static function channelOf(string $entry): string
    {
        $separator = strrpos($entry, self::SEPARATOR);

        return $separator === false ? $entry : substr($entry, 0, $separator);
    }
}
