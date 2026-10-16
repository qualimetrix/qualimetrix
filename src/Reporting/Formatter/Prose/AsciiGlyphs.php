<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Prose;

/** Product glyphs only; this does not transliterate other Unicode characters. */
final class AsciiGlyphs
{
    public const array REPLACEMENTS = [
        '·' => '.', '×' => 'x', '–' => '-', '—' => '-', '…' => '.',
        '→' => '>', '↳' => '>', '›' => '>', '─' => '-', '█' => '#', '░' => '.',
        '▓' => '#', '▲' => '^', '▼' => 'v', '✅' => '+', '✓' => '+',
        '❌' => 'x', '🔍' => '?',
    ];

    public static function replace(string $body): string
    {
        return strtr($body, self::REPLACEMENTS);
    }
}
