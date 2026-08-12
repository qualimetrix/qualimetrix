<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use InvalidArgumentException;

/**
 * Fixed-size, saturating pre-filter for rolling-hash candidates.
 *
 * Each two-bit slot records whether zero, one, or at least two hash windows
 * mapped to it during the first pass. A slot is never cleared, replaced, or
 * downgraded. Consequently a collision can promote an unrelated hash into
 * pass two, but it cannot hide a real repeated hash: its second observation
 * always saturates the same slot.
 *
 * This is deliberately a candidate filter rather than a duplicate verdict.
 * Pass two recomputes the exact rolling hash and indexes every position for
 * every candidate, where {@see DuplicateBlockFinder} verifies token equality.
 */
final class SaturatingCandidateFilter
{
    private const int DEFAULT_SLOT_COUNT = 8_388_608;
    private const int SLOTS_PER_BYTE = 4;
    private const int SATURATED = 2;

    private string $states;
    private int $slotMask;
    private bool $hasCandidates = false;

    public function __construct(int $slotCount = self::DEFAULT_SLOT_COUNT)
    {
        if ($slotCount < self::SLOTS_PER_BYTE || ($slotCount & ($slotCount - 1)) !== 0 || $slotCount % self::SLOTS_PER_BYTE !== 0) {
            throw new InvalidArgumentException('Slot count must be a power of two divisible by four.');
        }

        $this->states = str_repeat("\0", intdiv($slotCount, self::SLOTS_PER_BYTE));
        $this->slotMask = $slotCount - 1;
    }

    /**
     * Records one rolling hash without retaining either the hash or a
     * position. The two-bit state saturates at "two or more".
     */
    public function observe(int $hash): void
    {
        $slot = $hash & $this->slotMask;
        $byteIndex = intdiv($slot, self::SLOTS_PER_BYTE);
        $shift = ($slot % self::SLOTS_PER_BYTE) * 2;
        $byte = \ord($this->states[$byteIndex]);
        $state = ($byte >> $shift) & 0b11;

        if ($state >= self::SATURATED) {
            return;
        }

        $state++;
        $this->states[$byteIndex] = \chr(($byte & ~(0b11 << $shift)) | ($state << $shift));

        if ($state === self::SATURATED) {
            $this->hasCandidates = true;
        }
    }

    /**
     * Returns whether pass two must retain this hash's positions. It may be
     * true because of a compact-filter collision, never false for a hash
     * observed at least twice in pass one.
     */
    public function isCandidate(int $hash): bool
    {
        $slot = $hash & $this->slotMask;
        $byte = \ord($this->states[intdiv($slot, self::SLOTS_PER_BYTE)]);
        $shift = ($slot % self::SLOTS_PER_BYTE) * 2;

        return (($byte >> $shift) & 0b11) === self::SATURATED;
    }

    public function hasCandidates(): bool
    {
        return $this->hasCandidates;
    }
}
