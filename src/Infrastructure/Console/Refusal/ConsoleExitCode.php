<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

/**
 * The two shared Console exit codes: refusal by user input and internal error.
 * Every other code — success, and the analysis
 * outcomes 2/4/… — belongs to the command that produces it, not to this enum.
 */
enum ConsoleExitCode: int
{
    case Refusal = 3;
    case InternalError = 1;
}
