<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

/**
 * Immutable VO carrying a warning emitted during configuration resolution
 * that must be deferred until the user-configured logger is wired up.
 *
 * Configuration sources (e.g.,
 * {@see \Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory})
 * resolve before {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator}
 * swaps the holder's `NullLogger` for the real one. Logging a warning at
 * resolution time would therefore drop it on the floor. Instead, factories
 * emit `DeferredWarning`s; `RuntimeConfigurator::configure()` drains the
 * queue to the configured logger after `configureLogger()` runs.
 *
 * Step 1 of the architecture-rules follow-up plan wires this VO through
 * `ResolvedConfiguration` and the runtime configurator. Step 0 introduces
 * the type and an empty-list slot on
 * {@see \Qualimetrix\Configuration\Architecture\ArchitectureFactoryResult}
 * so the seam is in place.
 *
 * @see ArchitectureFactoryResult
 */
final readonly class DeferredWarning
{
    /**
     * @param string $level PSR-3 log level (e.g. `warning`, `info`).
     * @param string $message Human-readable warning text.
     * @param array<string, mixed> $context Optional structured context for the logger.
     */
    public function __construct(
        public string $level,
        public string $message,
        public array $context = [],
    ) {}
}
