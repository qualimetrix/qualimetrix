<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture;

use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;

/**
 * Result of {@see ArchitectureConfigurationFactory::fromArray()}.
 *
 * Bundles the typed {@see ArchitectureConfiguration} produced from the raw YAML
 * map with the list of {@see DeferredWarning}s the factory emitted while
 * processing it. The pipeline collects these warnings into
 * {@see \Qualimetrix\Configuration\Pipeline\ResolvedConfiguration::$deferredWarnings}
 * so that {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator} can
 * replay them once the user-configured logger has been wired up — at the time
 * {@see ArchitectureConfigurationFactory::fromArray()} runs, the logger holder
 * still carries a {@see \Psr\Log\NullLogger}, and any messages logged directly
 * would be dropped.
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
