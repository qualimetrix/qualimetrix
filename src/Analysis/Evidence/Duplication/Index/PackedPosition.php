<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Index;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;

/**
 * Bit-packing for (fileIdx, tokenOffset) pairs into a single int.
 *
 * The hash index built by {@see HashIndexBuilder} maps a rolling-hash value
 * to a list of positions where that hash occurred. Packing each position as
 * a single int — instead of a two-element array — avoids one array
 * allocation per token position, which matters because the index can hold
 * millions of entries for a large codebase (see {@see DuplicationDetector}
 * class docblock for the full memory-optimization rationale).
 *
 * Supports offsets through 2^32-1 and file indexes through 2^31-1 on 64-bit PHP.
 */
final class PackedPosition
{
    private const int OFFSET_BITS = 32;
    private const int OFFSET_MASK = (1 << self::OFFSET_BITS) - 1;
    private const int MAX_FILE_INDEX = (1 << 31) - 1;

    public static function pack(int $fileIdx, int $offset): int
    {
        if ($fileIdx < 0 || $fileIdx > self::MAX_FILE_INDEX || $offset < 0 || $offset > self::OFFSET_MASK) {
            throw new InvalidArgumentException('Packed duplication position exceeds the 31-bit file index or 32-bit token offset.');
        }

        return ($fileIdx << self::OFFSET_BITS) | $offset;
    }

    public static function fileIndex(int $packed): int
    {
        if ($packed < 0) {
            throw new InvalidArgumentException('Packed duplication position must be non-negative.');
        }

        return $packed >> self::OFFSET_BITS;
    }

    public static function offset(int $packed): int
    {
        if ($packed < 0) {
            throw new InvalidArgumentException('Packed duplication position must be non-negative.');
        }

        return $packed & self::OFFSET_MASK;
    }
}
