<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

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
 * Supports up to 1,048,575 tokens per file (20 bits) and ~8.7M files.
 */
final class PackedPosition
{
    private const int OFFSET_BITS = 20;
    private const int OFFSET_MASK = (1 << self::OFFSET_BITS) - 1; // 0xFFFFF

    public static function pack(int $fileIdx, int $offset): int
    {
        return ($fileIdx << self::OFFSET_BITS) | $offset;
    }

    public static function fileIndex(int $packed): int
    {
        return $packed >> self::OFFSET_BITS;
    }

    public static function offset(int $packed): int
    {
        return $packed & self::OFFSET_MASK;
    }
}
