<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Closure;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;

final class BalancedSegments
{
    /** @return list<array{int, int}> Relative offset and length of each segment. */
    public static function of(TokenStream $stream, int $offset, int $length): array
    {
        $segments = [];
        $index = 0;
        while ($index < $length) {
            $index = self::skipLeadingClosers($stream, $offset, $length, $index);
            $start = $index;
            $index = self::scanSegmentEnd($stream, $offset, $length, $index);

            if ($index > $start) {
                $segments[] = [$start, $index - $start];
            }
        }

        return $segments;
    }

    /**
     * @param list<int> $copies
     * @param Closure(list<int>, int): ?list<int> $reportable
     */
    public static function addAdmittedTo(
        TokenStream $stream,
        int $offset,
        int $length,
        array $copies,
        int $minTokens,
        DuplicateMatchCandidates $target,
        Closure $reportable,
    ): void {
        $admitted = false;
        foreach (self::of($stream, $offset, $length) as [$shift, $segmentLength]) {
            if ($segmentLength < $minTokens) {
                continue;
            }
            $shifted = array_map(
                static fn(int $copy): int => PackedPosition::pack(PackedPosition::fileIndex($copy), PackedPosition::offset($copy) + $shift),
                $copies,
            );
            $accepted = $reportable($shifted, $segmentLength);
            if ($accepted !== null) {
                $target->add($segmentLength, $accepted);
                $admitted = true;
            }
        }
        if (!$admitted) {
            $target->add($length, $copies);
        }
    }

    private static function skipLeadingClosers(TokenStream $stream, int $offset, int $length, int $index): int
    {
        while ($index < $length && \in_array($stream->values[$offset + $index], [')', ']', '}', ';', ','], true)) {
            $index++;
        }

        return $index;
    }

    private static function scanSegmentEnd(TokenStream $stream, int $offset, int $length, int $index): int
    {
        $depth = 0;
        while ($index < $length) {
            $value = $stream->values[$offset + $index];
            if (\in_array($value, ['{', '(', '[', '${'], true)) {
                $depth++;
            } elseif (\in_array($value, ['}', ')', ']'], true) && --$depth < 0) {
                break;
            }
            $index++;
        }

        return $index;
    }
}
