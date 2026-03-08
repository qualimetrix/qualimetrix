<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Logger proxy that delegates to LoggerHolder.
 *
 * This allows logger configuration to be set at runtime (in CheckCommand)
 * while services (Analyzer, PhpFileParser) are created during DI container compilation.
 *
 * Each log call delegates to the current logger in LoggerHolder, which can be
 * reconfigured via LoggerHolder::setLogger().
 */
final class DelegatingLogger extends AbstractLogger
{
    public function __construct(
        private readonly LoggerHolder $loggerHolder,
    ) {}

    /**
     * @param string $level Log level
     * @param string|Stringable $message Log message
     * @param array<string, mixed> $context Additional context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->loggerHolder->getLogger()->log($level, $message, $context);
    }
}
