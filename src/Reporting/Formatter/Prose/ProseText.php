<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Prose;

use Qualimetrix\Core\SourceText\SourceBytes;
use Qualimetrix\Reporting\Formatter\FormattedReport;

/** A prose body is one published string, unlike a structured document's fields. */
final class ProseText
{
    public static function publish(string $body, GlyphMode $mode): FormattedReport
    {
        $escaped = SourceBytes::isUtf8($body) ? 0 : 1;
        $body = SourceBytes::escapeInvalid($body);

        return new FormattedReport($mode === GlyphMode::Ascii ? AsciiGlyphs::replace($body) : $body, $escaped);
    }
}
