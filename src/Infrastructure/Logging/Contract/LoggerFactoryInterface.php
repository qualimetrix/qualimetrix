<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging\Contract;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Output\OutputInterface;

/** Creates the logger topology for one console run. */
interface LoggerFactoryInterface
{
    public function create(
        OutputInterface $output,
        ?string $logFile = null,
        string $level = LogLevel::INFO,
    ): LoggerInterface;
}
