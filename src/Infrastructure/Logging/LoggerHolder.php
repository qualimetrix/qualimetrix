<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Mutable logger switch owned by one container instance.
 *
 * It starts with a NullLogger. Configuring a console run replaces only this
 * holder's instance, allowing long-lived services to delegate without static
 * runtime state.
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
