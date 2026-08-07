<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

use InvalidArgumentException;

/**
 * The level a finding's group was accepted at — what a report means by
 * "accepted at 25, now 31" (§8 of the baseline-ceiling plan).
 *
 * A finding carries one only when it was measured against an applicable
 * baseline entry and exceeded it; see {@see Violation::reportedAsBreach()}.
 *
 * **Why this lives in `Core\Violation` rather than in `Baseline`.** The field
 * that holds it is a field of {@see Violation}, and `Core` may depend on
 * nothing — least of all on `Baseline`, which depends on `Core`. So the
 * *shape* of an accepted level is a violation-level fact and lives here; the
 * mechanism that decides when a level was exceeded stays in `Baseline`.
 *
 * The two shapes of {@see ChannelShape} are both expressible, and neither is
 * a special case of the other:
 *
 * - a `magnitude` channel accepted a *vector* — one number per member of the
 *   group, because a group of two duplicate blocks was accepted at both of
 *   their lengths and at neither alone;
 * - an `occurrence` channel accepted a *count* and nothing else. Its findings
 *   do report a number (a fixed marker, or a real one that is not a boundary
 *   in any later run's units), and it is deliberately not stored here: doing
 *   so would let a reader compare against a value the mechanism ignores.
 */
final readonly class AcceptedLevel
{
    /**
     * The magnitudes accepted at capture, or `null` on an `occurrence`
     * channel.
     *
     * @var ?list<float>
     */
    public ?array $magnitudes;

    /**
     * @param ?list<int|float> $magnitudes present exactly for `magnitude` channels, one per
     *                                     accepted member
     * @param int $count how many findings shared the identity at capture
     *
     * @throws InvalidArgumentException when the count is not positive, or when the magnitude
     *                                  list disagrees with it — an accepted level that cannot
     *                                  be true of any group would be a misleading thing to
     *                                  print next to a finding
     */
    public function __construct(?array $magnitudes, public int $count)
    {
        if ($count < 1) {
            throw new InvalidArgumentException(\sprintf(
                'An accepted level must accept at least one finding, got a count of %d.',
                $count,
            ));
        }

        if ($magnitudes === null) {
            $this->magnitudes = null;

            return;
        }

        if (\count($magnitudes) !== $count) {
            throw new InvalidArgumentException(\sprintf(
                'An accepted level of %d findings must hold %d magnitudes, got %d.',
                $count,
                $count,
                \count($magnitudes),
            ));
        }

        $this->magnitudes = array_map(static fn(int|float $magnitude): float => (float) $magnitude, $magnitudes);
    }

    public function shape(): ChannelShape
    {
        return $this->magnitudes === null ? ChannelShape::Occurrence : ChannelShape::Magnitude;
    }

    /**
     * Short human-readable form: the accepted magnitudes, or the accepted
     * number of findings when there are no magnitudes to name.
     *
     * Trailing zeros are trimmed so a magnitude captured as `40.0` prints as
     * `40` — the same collapse the file contract applies to the stored form,
     * so a user reading a report and a user reading the file see one number.
     */
    public function describe(): string
    {
        if ($this->magnitudes === null) {
            return $this->count === 1 ? '1 occurrence' : $this->count . ' occurrences';
        }

        return implode(', ', array_map(self::formatMagnitude(...), $this->magnitudes));
    }

    private static function formatMagnitude(float $magnitude): string
    {
        $formatted = rtrim(rtrim(\sprintf('%.6F', $magnitude), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
