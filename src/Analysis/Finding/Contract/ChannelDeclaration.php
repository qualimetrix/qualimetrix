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
 * The second is {@see $configurationError}, and it is **not authored**. A
 * producer cannot claim it: the constructor is private, the two public
 * factories both yield `false`, and {@see asConfigurationError()} is the only
 * expression in the language that yields `true`. Registry assembly calls it
 * for the channels of a {@see ConfigurationValidatorInterface} and for no
 * others, so "these findings are about the configuration" is a consequence of
 * the producer's type rather than a flag a rule can set on itself.
 */
final readonly class ChannelDeclaration
{
    /** @var non-empty-list<SymbolLevel> the levels this channel reports at, in {@see SymbolLevel} case order */
    public array $levels;

    /**
     * The constructor is private, so the only callers are the two factories
     * below. That makes the three refusals in this constructor and in
     * {@see canonicalLevels()} — magnitude without a direction, occurrence
     * with one, an empty level list — unreachable from outside the file:
     * every one of them is already excluded by a factory signature. They are
     * kept as backstops against an edit to those two factories, which is the
     * only way to reach them, and
     * {@see \Qualimetrix\Tests\Analysis\Finding\Unit\ChannelDeclarationTest}
     * pins the signatures that make them unreachable rather than the
     * unreachable branches themselves.
     *
     * @param array<SymbolLevel> $levels non-empty, in any order; both factories
     *                                   guarantee non-emptiness by arity
     */
    private function __construct(
        public ChannelShape $shape,
        public ?WorseDirection $direction,
        public bool $configurationError,
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
     * The levels are variadic with a mandatory first one, so a caller cannot
     * express the empty list at all, and the direction is non-nullable, so it
     * cannot express a magnitude without one. With the constructor private,
     * these two signatures are the whole enforcement of both invariants — the
     * constructor's own checks only guard against an edit to this factory.
     */
    public static function magnitude(WorseDirection $direction, SymbolLevel $level, SymbolLevel ...$moreLevels): self
    {
        return new self(ChannelShape::Magnitude, $direction, false, array_merge([$level], $moreLevels));
    }

    /**
     * An `occurrence` declaration — no direction, only presence/count matters.
     *
     * There is no direction parameter, which is what makes "an occurrence
     * channel must not declare one" unstatable rather than merely refused.
     */
    public static function occurrence(SymbolLevel $level, SymbolLevel ...$moreLevels): self
    {
        return new self(ChannelShape::Occurrence, null, false, array_merge([$level], $moreLevels));
    }

    /**
     * The same declaration, reclassified as reporting a mistake in the
     * *configuration* rather than debt in the code.
     *
     * The only expression that yields {@see $configurationError} `true`. A
     * topology test pins two things about it: production code contains exactly
     * one literal call, and no production file outside the assembly point and
     * its own documentation so much as names the method — so a call spelled
     * indirectly (a variable method name, a callable pair) is refused too. The
     * one call is the point where the channel registry is assembled and the
     * declaring producer's type is still known. A rule cannot reach that point,
     * which is the whole content of the classification: it follows from who
     * declared the channel, not from what the declaration says about itself.
     *
     * @internal registry assembly only
     */
    public function asConfigurationError(): self
    {
        return new self($this->shape, $this->direction, true, $this->levels);
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

        // Unreachable through either factory — both take a mandatory first
        // level — but not removable: it is what makes the `non-empty-list`
        // return type above provable, since the list is built by a loop.
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
        return $this->configurationError;
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
