<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture;

use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;

/**
 * Result of {@see ArchitectureConfigurationFactory::fromArray()}: the
 * resolved {@see ArchitectureConfiguration} plus any non-fatal warnings
 * that should be surfaced to the user once the runtime logger is wired
 * (see {@see DeferredWarning}).
 *
 * Step 0 of the architecture-rules follow-up plan introduces the type
 * and an empty `warnings` slot. Step 1 populates it with mutual-allow
 * detection and similar diagnostics, and drains the queue through the
 * configuration pipeline to the user-configured logger.
 */
final readonly class ArchitectureFactoryResult
{
    /**
     * @param list<DeferredWarning> $warnings Non-fatal warnings emitted while resolving the architecture configuration.
     */
    public function __construct(
        public ArchitectureConfiguration $configuration,
        public array $warnings = [],
    ) {}
}
