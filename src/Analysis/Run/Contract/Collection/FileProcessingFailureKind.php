<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Collection;

/** The collection-stage reason why a file did not produce metrics. */
enum FileProcessingFailureKind: string
{
    case Parse = 'parse';
    case Processing = 'processing';
}
