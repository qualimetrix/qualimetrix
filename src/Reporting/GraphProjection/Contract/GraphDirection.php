<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection\Contract;

/**
 * The DOT layout direction `graph:export --direction` accepts.
 *
 * The single owner of the four-word vocabulary: before this enum existed the
 * same four values were written three times over with no shared source —
 * the option's help text, {@see \Qualimetrix\Reporting\GraphProjection\DotExporterOptions}'s
 * docblock, and the DOT `rankdir` attribute itself.
 */
enum GraphDirection: string
{
    case LR = 'LR';
    case TB = 'TB';
    case RL = 'RL';
    case BT = 'BT';
}
