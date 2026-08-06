<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

use InvalidArgumentException;
use Qualimetrix\Core\Observation\WorseDirection;

/**
 * The two facts a channel declares for baseline purposes: its shape, and —
 * for a `magnitude` shape only — the direction that makes its reported
 * number comparable.
 *
 * Nothing else belongs here: no axis name, no threshold binding, no
 * epsilon. A channel that declares no {@see ChannelDeclaration} at all is
 * not an error state — it is simply not baselineable (see
 * {@see ChannelDeclarationRegistryInterface}).
 *
 * The invariant below is the whole contract: a direction is present exactly
 * when the shape is `magnitude`. An `occurrence` channel's reported number
 * (a fixed marker such as `1.0`, or none at all) carries no direction to
 * declare; a `magnitude` channel cannot be compared without one.
 */
final readonly class ChannelDeclaration
{
    public function __construct(
        public ChannelShape $shape,
        public ?WorseDirection $direction = null,
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
}
