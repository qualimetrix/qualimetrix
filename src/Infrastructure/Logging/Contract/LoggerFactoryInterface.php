<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging\Contract;

use Psr\Log\LoggerInterface;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates the logger topology for one console run.
 *
 * The caller passes the diagnostic writer it was given by the error stream's
 * owner; this contract does not select a stream of its own.
 *
 * `$level` is the `--log-level` the user wrote, or null when they wrote none.
 * The difference matters: a written level holds on the console at every
 * verbosity, and only an unwritten one lets verbosity choose.
 *
 * @throws LogFileUnavailable when `$logFile` is blank; only null means no log file
 */
interface LoggerFactoryInterface
{
    public function create(
        OutputInterface $diagnostics,
        ?string $logFile,
        ?string $level,
    ): LoggerInterface;

    public function attachFileTarget(HeldTarget $target): void;

    public function settle(): void;

    public function reset(): void;
}
