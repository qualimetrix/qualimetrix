<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

/**
 * Shared outcomes for refused input, incomplete analysis and internal errors.
 */
enum ConsoleExitCode: int
{
    case Refusal = 3;
    case AnalysisIncomplete = 4;
    case InternalError = 5;
}
