<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Captured PSR-3 log record produced during configuration resolution.
 *
 * Configuration resolution runs before the user-facing logger is configured
 * (see {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator::configureLogger()}),
 * so any warnings emitted at that stage must be deferred and replayed once the
 * logger is ready. Each warning carries a PSR-3 level, message, and optional
 * context, which {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator}
 * forwards verbatim to the configured logger via {@see LoggerInterface::log()}.
 *
 * Configuration sources (e.g.,
 * {@see \Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory})
 * append `DeferredWarning`s into
 * {@see \Qualimetrix\Architecture\Configuration\ArchitectureFactoryResult::$warnings};
 * the pipeline forwards them through
 * {@see ResolvedConfiguration::$deferredWarnings} into the runtime configurator.
 *
 * Use {@see self::warning()} for the common case (level = {@see LogLevel::WARNING}).
 * For other severities pass {@see LogLevel} constants directly to the constructor.
 *
 * @see \Qualimetrix\Architecture\Configuration\ArchitectureFactoryResult
 *
 * @phpstan-type LogLevelString 'emergency'|'alert'|'critical'|'error'|'warning'|'notice'|'info'|'debug'
 */
final readonly class DeferredWarning
{
    /**
     * @param LogLevelString $level A PSR-3 {@see LogLevel} constant value
     * @param string $message Pre-rendered message ready to be passed to {@see LoggerInterface::log()}
     * @param array<string, mixed> $context Optional PSR-3 context (forwarded verbatim)
     */
    public function __construct(
        public string $level,
        public string $message,
        public array $context = [],
    ) {}

    /**
     * Convenience factory for the common {@see LogLevel::WARNING} case.
     *
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): self
    {
        return new self(LogLevel::WARNING, $message, $context);
    }
}
