<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;

final class OutputEncoding
{
    public static function fromEnvironment(string|false $value): GlyphMode
    {
        $modes = [
            '1' => GlyphMode::Ascii, 'true' => GlyphMode::Ascii,
            'yes' => GlyphMode::Ascii, 'on' => GlyphMode::Ascii,
            '' => GlyphMode::Unicode, '0' => GlyphMode::Unicode,
            'false' => GlyphMode::Unicode, 'no' => GlyphMode::Unicode, 'off' => GlyphMode::Unicode,
        ];

        return $modes[strtolower($value === false ? '' : $value)]
            ?? throw ConfigurationRefusal::aboutInput(
                \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin::of(
                    \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::Environment,
                    'QMX_ASCII',
                ),
                'QMX_ASCII accepts 1/true/yes/on or 0/false/no/off/empty; received ' . $value . '.',
            );
    }
}
