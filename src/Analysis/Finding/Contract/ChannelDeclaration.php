<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;
use Qualimetrix\Core\Observation\WorseDirection;

/**
 * What a channel declares for baseline purposes: its shape, — for a
 * `magnitude` shape only — the direction that makes its reported number
 * comparable, and whether its findings may be accepted as debt at all.
 *
 * Nothing else belongs here: no axis name, no threshold binding, no
 * epsilon. A channel that declares no {@see ChannelDeclaration} at all is
 * not an error state — it is simply not baselineable (see
 * {@see ChannelDeclarationRegistryInterface}).
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
 * The four layer-policy diagnostics needed both and got the wrong one — see
 * {@see ChannelAcceptability}.
 */
final readonly class ChannelDeclaration
{
    public function __construct(
        public ChannelShape $shape,
        public ?WorseDirection $direction = null,
        public ChannelAcceptability $acceptability = ChannelAcceptability::AcceptableAsDebt,
    ) {
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
     * A `magnitude` declaration in the given direction.
     */
    public static function magnitude(WorseDirection $direction): self
    {
        return new self(ChannelShape::Magnitude, $direction);
    }

    /**
     * An `occurrence` declaration — no direction, only presence/count matters.
     */
    public static function occurrence(): self
    {
        return new self(ChannelShape::Occurrence);
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
    public static function configurationError(): self
    {
        return new self(ChannelShape::Occurrence, null, ChannelAcceptability::ConfigurationError);
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
}
