<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

/** A terminal failure category for one discovered file. */
enum AnalysisFailureKind: string
{
    case Parse = 'parse';
    case Processing = 'processing';
}
