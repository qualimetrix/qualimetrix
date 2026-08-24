<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Suppression;

/**
 * Defines the scope of a suppression tag.
 */
enum SuppressionType: string
{
    /** Suppress at symbol level (class/method docblock). */
    case Symbol = 'symbol';

    /** Suppress the next line only. */
    case NextLine = 'next-line';

    /** Suppress all matching findings in the entire file. */
    case File = 'file';
}
