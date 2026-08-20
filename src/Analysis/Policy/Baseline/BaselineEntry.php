<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;

/**
 * One accepted group: an identity, how many findings shared it at capture,
 * and — for a `magnitude` channel — the magnitude each of them reported.
 *
 * ```text
 * entry(identity) = { magnitudes: number[] | null, count: int }
 * ```
 *
 * **`count` is not stored when `magnitudes` is present.** The two are never
 * independent — the constructor already requires `count === count(magnitudes)`
 * — so {@see toArray()} omits `count` for a magnitude-shaped entry and
 * serializes only `magnitudes`. {@see BaselineEntryValues::decode()} is the
 * inverse on the way back in: it derives `count` from the magnitude list's
 * length instead of reading a field the file no longer carries. An
 * occurrence-shaped entry, which has no magnitudes to count, still writes
 * `count` as before.
 *
 * The comparison that decides whether a later run's group is still accepted
 * lives with the filter, not here (ADR 0017); this
 * class owns the entry's *shape* and its normal form.
 *
 * **Magnitudes are normalized here, not at the call site.** Every
 * construction path — capture, load, a future `update` — goes through this
 * constructor, so `round($v, 6)` applies once and applies to all of them.
 * Normalizing at capture only would leave the stored side rounded and the
 * recomputed side raw, which is exactly the asymmetry that makes a zero
 * tolerance unsound.
 *
 * **The list is stored ascending.** That is a determinism convention and
 * nothing more: the acceptance rule counts members per severity level and
 * never reads the list positionally, so no consumer re-sorts it and no
 * consumer may assume the order means anything. Ascending is checkable
 * without knowing the channel's direction, which computed metrics resolve
 * only at run time.
 */
final readonly class BaselineEntry
{
    /** Decimal places every stored and compared magnitude is rounded to (ADR 0017). */
    public const int MAGNITUDE_PRECISION = 6;

    /** @var ?list<float> */
    public ?array $magnitudes;

    /**
     * @param ?list<int|float> $magnitudes present exactly for `magnitude` channels
     *
     * @throws InvalidArgumentException when the entry cannot be a valid entry at all —
     *                                  a non-positive count, a magnitude list whose length
     *                                  disagrees with it, or a non-finite magnitude. The
     *                                  loader catches this and turns the line inert rather
     *                                  than failing the run (ADR 0017)
     */
    public function __construct(
        public BaselineIdentity $identity,
        ?array $magnitudes,
        public int $count,
        public ?BaselineEntryMode $mode = null,
    ) {
        if ($count < 1) {
            throw new InvalidArgumentException(\sprintf(
                'A baseline entry count must be a positive integer, got %d.',
                $count,
            ));
        }

        if ($magnitudes === null) {
            $this->magnitudes = null;

            return;
        }

        if (\count($magnitudes) !== $count) {
            throw new InvalidArgumentException(\sprintf(
                'A baseline entry must hold exactly %d magnitudes, got %d.',
                $count,
                \count($magnitudes),
            ));
        }

        $normalized = [];
        foreach ($magnitudes as $magnitude) {
            $normalized[] = self::normalizeMagnitude($magnitude);
        }

        usort($normalized, static fn(float $a, float $b): int => $a <=> $b);

        $this->magnitudes = $normalized;
    }

    /**
     * Rounds a magnitude to the stored precision.
     *
     * Six decimal places survive any `serialize_precision`, which is what
     * makes the stored value round-trip to itself regardless of the reader's
     * ini — and therefore what earns the zero tolerance in the comparison.
     * Values that their own rules already rounded (one decimal place for
     * `maintainability.index` and computed metrics) pass through unchanged.
     *
     * Negative zero collapses to positive zero: the two are numerically
     * equal but spell differently in JSON, and one written form per value is
     * a precondition for byte stability.
     *
     * @throws InvalidArgumentException when the value is NaN or infinite
     */
    public static function normalizeMagnitude(int|float $magnitude): float
    {
        $value = (float) $magnitude;

        if (!is_finite($value)) {
            throw new InvalidArgumentException(
                'A baseline magnitude must be finite — NaN and infinity are not boundaries.',
            );
        }

        $rounded = round($value, self::MAGNITUDE_PRECISION);

        // `-0.0 === 0.0` holds, so this catches negative zero as well.
        return $rounded === 0.0 ? 0.0 : $rounded;
    }

    /**
     * The shape this entry claims, read off its own contents. Compared
     * against the channel's declared shape at load time; a disagreement in
     * either direction makes the entry inert (ADR 0017).
     */
    public function shape(): ChannelShape
    {
        return $this->magnitudes === null ? ChannelShape::Occurrence : ChannelShape::Magnitude;
    }

    public function selector(): EntrySelector
    {
        return $this->identity->selector();
    }

    /**
     * The entry object of ADR 0017, with a fixed key order so that the same entry
     * always produces the same bytes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['channel' => $this->identity->channel->toKey()];

        if ($this->identity->occurrenceKey !== null) {
            $data['occurrence'] = $this->identity->occurrenceKey;
        }

        if ($this->identity->edge !== null) {
            $data['edge'] = $this->identity->edge->toArray();
        }

        if ($this->magnitudes !== null) {
            $data['magnitudes'] = $this->magnitudes;
        } else {
            $data['count'] = $this->count;
        }

        if ($this->mode !== null) {
            $data['mode'] = $this->mode->value;
        }

        return $data;
    }
}
