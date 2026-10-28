<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Token\TokenStream;

/**
 * The successive matches that keep a group's anchor, longest agreement last.
 *
 * A group of members agreeing on a prefix extends while every member still
 * agrees with the anchor; where some stop, they leave as side groups and the
 * rest continue. A long periodic run sheds one member per period, so
 * re-reading every remaining member at every node is quadratic in the run.
 * Here each member leaves once, in order of its precomputed common-prefix
 * length, and per-node answers come from incremental state: a skip list of
 * departed members for non-overlapping copies and a count of remaining
 * members whose data run is shorter than the node.
 *
 * Chains wait under their side groups, nested as deep as the group's
 * structure, so per-member state stays at two integers and is compacted once
 * a quarter of it has departed.
 */
final class AnchorChain
{
    private const int INDEX_BITS = 32;
    private const int INDEX_MASK = (1 << self::INDEX_BITS) - 1;
    private const int COMPACTION_FLOOR = 64;

    /** @var list<int> packed positions in file/offset order */
    private array $members;

    /** @var list<int> non-anchor members as `length << 32 | index`, ascending: departure order */
    private array $leaving = [];
    private int $leavingCursor = 0;

    /** @var array<int, int> member index → data-run length, for members inside a data run */
    private array $dataLengths = [];

    /** @var list<int> data members as `data-run length << 32 | index`, ascending */
    private array $dataOrder = [];
    private int $dataCursor = 0;

    /** Remaining members whose data run is shorter than this length are counted as code. */
    private int $countedBelow = 1;
    private int $remainingCode = 0;

    /** @var array<int, int> departed member index → a later index that may remain */
    private array $departed = [];
    private int $work;

    /**
     * @param array<int, TokenStream> $streams
     * @param non-empty-list<int> $members packed positions in file/offset order
     * @param list<int> $lengths common-prefix length of each member with the anchor
     */
    public function __construct(
        private readonly array $streams,
        array $members,
        array $lengths,
        DataRuns $dataRuns,
    ) {
        $this->members = $members;
        foreach ($members as $index => $packed) {
            if ($index > 0) {
                $this->leaving[] = $lengths[$index] << self::INDEX_BITS | $index;
            }
            $dataLength = $dataRuns->endAt($packed) - PackedPosition::offset($packed);
            if ($dataLength > 0) {
                $this->dataLengths[$index] = $dataLength;
                $this->dataOrder[] = $dataLength << self::INDEX_BITS | $index;
            } else {
                $this->remainingCode++;
            }
        }
        sort($this->leaving, \SORT_NUMERIC);
        sort($this->dataOrder, \SORT_NUMERIC);
        $this->work = \count($members);
    }

    /** Token length of the current match: the shortest agreement among remaining members. */
    public function length(): int
    {
        return $this->leaving[$this->leavingCursor] >> self::INDEX_BITS;
    }

    /** Whether every remaining member lies wholly in one data run for the current length. */
    public function isData(): bool
    {
        $length = $this->length();
        $count = \count($this->dataOrder);
        while ($this->dataCursor < $count && $this->dataOrder[$this->dataCursor] >> self::INDEX_BITS < $length) {
            if (!isset($this->departed[$this->dataOrder[$this->dataCursor] & self::INDEX_MASK])) {
                $this->remainingCode++;
            }
            $this->dataCursor++;
            $this->work++;
        }
        $this->countedBelow = $length;

        return $this->remainingCode === 0;
    }

    /**
     * Remaining members whose current match overlaps no earlier one of its file.
     *
     * @return list<int>
     */
    public function distinctCopies(): array
    {
        $length = $this->length();
        $count = \count($this->members);
        $copies = [];
        $index = $this->remainingFrom(0);
        while ($index < $count) {
            $copies[] = $this->members[$index];
            // Packing is file-major, so the bound is either a later copy of this file or the next file's first.
            $index = $this->remainingFrom($this->firstAtOrAfter($this->members[$index] + $length, $index + 1, $count));
            $this->work++;
        }

        return $copies;
    }

    /**
     * Removes the members that stop agreeing at the current length and
     * returns those that continue together, grouped by their next token in
     * first-appearance order. The chain continues while another member
     * remains with the anchor; no member outlasts the anchor's stream.
     *
     * @return array{list<non-empty-list<int>>, bool}
     */
    public function split(): array
    {
        $length = $this->length();
        $leavingCount = \count($this->leaving);
        $end = $this->leavingCursor;
        while ($end < $leavingCount && $this->leaving[$end] >> self::INDEX_BITS === $length) {
            $end++;
        }
        $continues = $end < $leavingCount;

        $groups = [];
        for (; $this->leavingCursor < $end; $this->leavingCursor++) {
            $index = $this->leaving[$this->leavingCursor] & self::INDEX_MASK;
            if ($continues) {
                // A finished chain is never read again; skipping its bookkeeping keeps its peak flat.
                $this->depart($index);
            }
            $packed = $this->members[$index];
            $tokens = $this->streams[PackedPosition::fileIndex($packed)]->values;
            $position = PackedPosition::offset($packed) + $length;
            if (isset($tokens[$position])) {
                $groups[$tokens[$position]][] = $packed;
            }
            $this->work++;
        }

        $sides = [];
        foreach ($groups as $group) {
            if (\count($group) >= 2) {
                $sides[] = $group;
            }
        }

        if ($continues && \count($this->members) > self::COMPACTION_FLOOR && \count($this->departed) * 4 > \count($this->members)) {
            $this->compact();
        }

        return [$sides, $continues];
    }

    /** Member steps spent by this chain, construction included. */
    public function work(): int
    {
        return $this->work;
    }

    private function depart(int $index): void
    {
        $this->departed[$index] = $index + 1;
        if (($this->dataLengths[$index] ?? 0) < $this->countedBelow) {
            $this->remainingCode--;
        }
    }

    private function compact(): void
    {
        $map = [];
        $members = [];
        $dataLengths = [];
        foreach ($this->members as $index => $packed) {
            if (!isset($this->departed[$index])) {
                $map[$index] = \count($members);
                if (isset($this->dataLengths[$index])) {
                    $dataLengths[$map[$index]] = $this->dataLengths[$index];
                }
                $members[] = $packed;
            }
        }

        // The index map is monotonic, so re-keyed lists stay sorted.
        $leaving = [];
        for ($cursor = $this->leavingCursor, $count = \count($this->leaving); $cursor < $count; $cursor++) {
            $key = $this->leaving[$cursor];
            $leaving[] = $key & ~self::INDEX_MASK | $map[$key & self::INDEX_MASK];
        }

        $dataOrder = [];
        $dataCursor = 0;
        foreach ($this->dataOrder as $cursor => $key) {
            $index = $key & self::INDEX_MASK;
            if (isset($map[$index])) {
                $dataOrder[] = $key & ~self::INDEX_MASK | $map[$index];
                if ($cursor < $this->dataCursor) {
                    $dataCursor++;
                }
            }
        }

        $this->work += \count($this->members) + \count($this->dataOrder);
        $this->members = $members;
        $this->dataLengths = $dataLengths;
        $this->leaving = $leaving;
        $this->leavingCursor = 0;
        $this->dataOrder = $dataOrder;
        $this->dataCursor = $dataCursor;
        $this->departed = [];
    }

    private function remainingFrom(int $index): int
    {
        $root = $index;
        while (isset($this->departed[$root])) {
            $root = $this->departed[$root];
        }
        while ($index !== $root) {
            $next = $this->departed[$index];
            $this->departed[$index] = $root;
            $index = $next;
        }

        return $root;
    }

    /** First index in [$low, $high) whose packed position is at least `$bound`, or `$high`. */
    private function firstAtOrAfter(int $bound, int $low, int $high): int
    {
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($this->members[$middle] < $bound) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
