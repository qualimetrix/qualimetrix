<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging\Contract;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates the logger topology for one console run.
 *
 * The caller passes the diagnostic writer it was given by the error stream's
 * owner; this contract does not select a stream of its own.
 */
interface LoggerFactoryInterface
{
    public function create(
        OutputInterface $diagnostics,
        ?string $logFile = null,
        string $level = LogLevel::INFO,
    ): LoggerInterface;
}
