<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;

final class BalancedSegments
{
    /** @return list<array{int, int}> Relative offset and length of each segment. */
    public static function of(TokenStream $stream, int $offset, int $length): array
    {
        $segments = [];
        $index = 0;
        while ($index < $length) {
            while ($index < $length && \in_array($stream->values[$offset + $index], [')', ']', '}', ';', ','], true)) {
                $index++;
            }

            $start = $index;
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

            if ($index > $start) {
                $segments[] = [$start, $index - $start];
            }
        }

        return $segments;
    }
}
