<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;

final class OutputEncoding
{
    public static function fromEnvironment(string|false $value): GlyphMode
    {
        return match (strtolower($value === false ? '' : $value)) {
            '1', 'true', 'yes', 'on' => GlyphMode::Ascii,
            '', '0', 'false', 'no', 'off' => GlyphMode::Unicode,
            default => throw ConfigurationRefusal::aboutCommandLineInput(
                'QMX_ASCII',
                'QMX_ASCII accepts 1/true/yes/on or 0/false/no/off/empty; received ' . $value . '.',
            ),
        };
    }
}
