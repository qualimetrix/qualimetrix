<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Core\Observation\WorseDirection;

/**
 * What a channel declares: its shape, — for a `magnitude` shape only — the
 * direction that makes its reported number comparable, whether its findings
 * may be accepted as debt at all, and the levels of the aggregation tree it
 * reports at.
 *
 * Nothing else belongs here: no axis name, no threshold binding, no
 * epsilon. A channel that declares no {@see ChannelDeclaration} at all is
 * not an error state — it is simply not baselineable (see
 * {@see ChannelDeclarationRegistryInterface}).
 *
 * **Levels are declared in full, never inferred.** The list is what the
 * registry *accepts* for this channel; emission still derives a finding's
 * level from its subject, so the two can disagree and a drift test compares
 * them against an observed run. A channel that reports at one level says so
 * with one entry — there is no empty list meaning "whatever the subject
 * turns out to be", because the registry is built before any finding exists
 * and could not resolve it.
 *
 * Two invariants, and the first is the whole shape contract: a direction is
 * present exactly when the shape is `magnitude`. An `occurrence` channel's
 * reported number (a fixed marker such as `1.0`, or none at all) carries no
 * direction to declare; a `magnitude` channel cannot be compared without one.
 *
 * The second is {@see $acceptability}, and it is deliberately *not* inferred
 * from anything else here. "Not baselineable" used to be expressible only by
 * declaring nothing at all, which meant a channel had to choose between
 * declaring how it compares and declaring that accepting it is illegitimate.
 * The layer-policy diagnostics needed both and got the wrong one — see
 * {@see ChannelAcceptability}.
 *
 * @qmx-ignore health.cohesion -- Predicate methods each answer one independent question over disjoint fields of one flat declaration; there is no shared instance state to group them by.
 */
final readonly class ChannelDeclaration
{
    /** @var non-empty-list<SymbolLevel> the levels this channel reports at, in {@see SymbolLevel} case order */
    public array $levels;

    /**
     * @param array<SymbolLevel> $levels non-empty, in any order; the factories
     *                                   guarantee non-emptiness by arity, and
     *                                   {@see canonicalLevels()} refuses an
     *                                   empty one at run time for callers
     *                                   that assemble the list themselves
     */
    public function __construct(
        public ChannelShape $shape,
        public ?WorseDirection $direction,
        public ChannelAcceptability $acceptability,
        array $levels,
    ) {
        $this->levels = self::canonicalLevels($levels);

        if ($shape === ChannelShape::Magnitude && $direction === null) {
            throw new InvalidArgumentException(
                'A magnitude channel must declare a WorseDirection (higher or lower is worse).',
            );
        }

        if ($shape === ChannelShape::Occurrence && $direction !== null) {
            throw new InvalidArgumentException(
                'An occurrence channel must not declare a WorseDirection — its reported number is not a magnitude.',
            );
        }
    }

    /**
     * A `magnitude` declaration in the given direction, reporting at the
     * given levels.
     *
     * The levels are variadic with a mandatory first one, so a caller
     * reaching for a factory cannot express the empty list at all. That is a
     * property of the three factories and not of the type: the constructor
     * below is the general form, takes a plain array, and enforces the same
     * invariant at run time. Every production declaration goes through a
     * factory; a caller that assembles a list itself gets an exception, not
     * a compile error.
     */
    public static function magnitude(WorseDirection $direction, SymbolLevel $level, SymbolLevel ...$moreLevels): self
    {
        return new self(ChannelShape::Magnitude, $direction, ChannelAcceptability::AcceptableAsDebt, array_merge([$level], $moreLevels));
    }

    /**
     * An `occurrence` declaration — no direction, only presence/count matters.
     */
    public static function occurrence(SymbolLevel $level, SymbolLevel ...$moreLevels): self
    {
        return new self(ChannelShape::Occurrence, null, ChannelAcceptability::AcceptableAsDebt, array_merge([$level], $moreLevels));
    }

    /**
     * A channel whose findings report a configuration mistake rather than
     * code debt.
     *
     * The shape is `occurrence` because a configuration error has no
     * magnitude to compare — there is no "how much" to ratchet down, only
     * "the configuration does not describe the code". A named constructor
     * rather than an acceptability argument on {@see occurrence()} so that a
     * producer declaring one of these says so in one word, and so that
     * declaring it does not require reaching for the enum.
     */
    public static function configurationError(SymbolLevel $level, SymbolLevel ...$moreLevels): self
    {
        return new self(ChannelShape::Occurrence, null, ChannelAcceptability::ConfigurationError, array_merge([$level], $moreLevels));
    }

    /**
     * The declared levels in {@see SymbolLevel} case order, so that two
     * declarations of the same channel compare equal whatever order their
     * authors wrote them in. A repeated level is refused rather than
     * collapsed: it says the author believes the channel reports twice at one
     * level, and that belief is wrong somewhere.
     *
     * @param array<SymbolLevel> $levels
     *
     * @return non-empty-list<SymbolLevel>
     */
    private static function canonicalLevels(array $levels): array
    {
        $canonical = [];

        foreach (SymbolLevel::cases() as $case) {
            $occurrences = \count(array_filter($levels, static fn(SymbolLevel $level): bool => $level === $case));

            if ($occurrences > 1) {
                throw new InvalidArgumentException(\sprintf(
                    'A channel declares the level "%s" more than once.',
                    $case->value,
                ));
            }

            if ($occurrences === 1) {
                $canonical[] = $case;
            }
        }

        if ($canonical === []) {
            throw new InvalidArgumentException('A channel must declare at least one level it reports at.');
        }

        return $canonical;
    }

    /**
     * Whether findings on this channel report a configuration mistake rather
     * than code debt — the single question every baseline path asks before
     * it compares anything.
     */
    public function isConfigurationError(): bool
    {
        return $this->acceptability === ChannelAcceptability::ConfigurationError;
    }

    /**
     * Whether a smaller reported number is worse for this channel — `null`
     * for an `occurrence` shape, which carries no direction to ask about.
     *
     * Exists so a consumer that only ever needs to flip a ratio (e.g.
     * {@see \Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry})
     * does not have to import {@see WorseDirection} itself just to compare
     * against one of its two cases.
     */
    public function isLowerWorse(): ?bool
    {
        return $this->direction === null ? null : $this->direction === WorseDirection::Lower;
    }
}
