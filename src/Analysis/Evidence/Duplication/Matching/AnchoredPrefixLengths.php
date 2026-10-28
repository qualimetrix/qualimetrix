<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Token\TokenStream;

/**
 * Exact common-prefix length of every group member with the group's first
 * member, the anchor.
 *
 * Members inside a periodic run overlap each other, so comparing each one
 * from scratch costs the run length per member: quadratic in the run. A
 * member that starts inside the span already matched by an earlier member of
 * its file reuses that match through the anchor's own Z-array (Gusfield's
 * Z-box), so a file costs its matched span plus the anchor's self-similar
 * prefix.
 *
 * Two copies of one run split into a side group per period, each anchored
 * one period later on the same file pair and shift. A long match is
 * therefore kept per diagonal for the whole search: a later pair starting
 * inside it ends where it ends. Members reached by neither are compared
 * token by token, which is what an isolated copy costs anyway.
 *
 * One instance serves one search; it is not reentrant.
 */
final class AnchoredPrefixLengths
{
    /** Shorter matches are cheaper to compare again than to keep. */
    private const int KEPT_MATCH_FLOOR = 256;

    /** Nested runs across many files yield millions of long diagonals; only recent ones pay off. */
    private const int KEPT_DIAGONALS = 4096;

    /** @var array<string, array{int, int}> "anchor file:file:shift" → anchor-side start and exclusive end of a maximal match */
    private array $diagonals = [];
    private int $comparisons = 0;

    /** @var list<string> */
    private array $pattern = [];
    private int $anchorFile = 0;
    private int $base = 0;
    private int $length = 0;

    /** @var array<int, int> Z-array of the anchor suffix, computed lazily from index 1 */
    private array $selfPrefix = [];
    private int $computed = 0;
    private int $zLeft = 0;
    private int $zRight = 0;

    /**
     * @param array<int, TokenStream> $streams
     */
    public function __construct(private readonly array $streams) {}

    /**
     * @param non-empty-list<int> $members packed positions in file/offset order
     * @param int $known tokens every member is known to share with the anchor
     *
     * @return list<int> lengths indexed like `$members`
     */
    public function of(array $members, int $known): array
    {
        $this->anchorFile = PackedPosition::fileIndex($members[0]);
        $this->pattern = $this->streams[$this->anchorFile]->values;
        $this->base = PackedPosition::offset($members[0]);
        $this->length = \count($this->pattern) - $this->base;
        $this->selfPrefix = [];
        $this->computed = 0;
        $this->zLeft = 0;
        $this->zRight = 0;

        $result = [$this->length];
        $file = -1;
        $text = [];
        $spanStart = 0;
        $spanEnd = 0;

        for ($i = 1, $count = \count($members); $i < $count; $i++) {
            $fileIndex = PackedPosition::fileIndex($members[$i]);
            $offset = PackedPosition::offset($members[$i]);
            if ($fileIndex !== $file) {
                $file = $fileIndex;
                $text = $this->streams[$fileIndex]->values;
                $spanEnd = 0;
            }

            $matched = $known;
            if ($offset < $spanEnd) {
                // $text[$spanStart, $spanEnd) equals the anchor's prefix of that length.
                $reused = $this->selfPrefixAt($offset - $spanStart);
                if ($reused < $spanEnd - $offset) {
                    $result[] = $reused;

                    continue;
                }
                $matched = max($matched, $spanEnd - $offset);
            }

            $diagonal = "{$this->anchorFile}:{$fileIndex}:" . ($offset - $this->base);
            $matched = $this->keptEnd($diagonal) ?? $this->keep($diagonal, $this->extend($text, $offset, $matched));
            $result[] = $matched;
            if ($offset + $matched > $spanEnd) {
                $spanStart = $offset;
                $spanEnd = $offset + $matched;
            }
        }

        return $result;
    }

    /** Token comparisons made since construction. */
    public function comparisons(): int
    {
        return $this->comparisons;
    }

    /** The anchor's exact match on a diagonal, when a kept match there covers the anchor. */
    private function keptEnd(string $diagonal): ?int
    {
        $kept = $this->diagonals[$diagonal] ?? null;
        if ($kept === null || $kept[0] > $this->base || $this->base >= $kept[1]) {
            return null;
        }
        $this->comparisons++;

        return $kept[1] - $this->base;
    }

    private function keep(string $diagonal, int $matched): int
    {
        if ($matched >= self::KEPT_MATCH_FLOOR) {
            if (\count($this->diagonals) >= self::KEPT_DIAGONALS) {
                $this->diagonals = [];
            }
            $this->diagonals[$diagonal] = [$this->base, $this->base + $matched];
        }

        return $matched;
    }

    /**
     * @param list<string> $text
     */
    private function extend(array $text, int $offset, int $matched): int
    {
        $limit = min($this->length, \count($text) - $offset);
        $start = $matched;
        while ($matched < $limit && $text[$offset + $matched] === $this->pattern[$this->base + $matched]) {
            $matched++;
        }
        $this->comparisons += $matched - $start + 1;

        return $matched;
    }

    private function extendSelf(int $shift, int $matched): int
    {
        $start = $matched;
        while ($shift + $matched < $this->length && $this->pattern[$this->base + $matched] === $this->pattern[$this->base + $shift + $matched]) {
            $matched++;
        }
        $this->comparisons += $matched - $start + 1;

        return $matched;
    }

    private function selfPrefixAt(int $index): int
    {
        while ($this->computed < $index) {
            $i = ++$this->computed;
            $z = $i < $this->zRight ? min($this->zRight - $i, $this->selfPrefix[$i - $this->zLeft]) : 0;
            if ($i + $z >= $this->zRight) {
                $z = $this->extendSelf($i, $z);
            }
            $this->selfPrefix[$i] = $z;
            if ($i + $z > $this->zRight) {
                $this->zLeft = $i;
                $this->zRight = $i + $z;
            }
        }

        return $this->selfPrefix[$index];
    }
}
