<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

/** Run-internal policy for generated files discovered for one analysis. */
enum GeneratedFilePolicy: string
{
    case Include = 'include';
    case Exclude = 'exclude';
}
