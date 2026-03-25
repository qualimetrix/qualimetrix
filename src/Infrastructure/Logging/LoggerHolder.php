<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Mutable holder for the current logger instance.
 *
 * This allows runtime configuration of logging (similar to ConfigurationHolder).
 * Initially contains NullLogger, but can be reconfigured in CheckCommand
 * based on CLI options (-v, --log-file, etc.).
 */
final class LoggerHolder
{
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * Sets the logger instance.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Gets the current logger instance.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
