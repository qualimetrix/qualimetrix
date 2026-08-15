<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationWarning;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;

/**
 * Result of {@see ArchitectureConfigurationFactory::fromArray()}.
 *
 * Bundles the typed {@see ArchitectureConfiguration} with the non-fatal
 * {@see ArchitectureConfigurationWarning}s produced while Architecture consumes
 * its contributions from the neutral Configuration document. RuntimeConfigurator
 * invokes that consumption after configuring the user logger and logs the returned
 * warning values immediately.
 */
final readonly class ArchitectureFactoryResult implements ResolvedArchitecturePolicyInterface
{
    /**
     * @param list<ArchitectureConfigurationWarning> $warnings Non-fatal warnings emitted while resolving the architecture configuration.
     */
    public function __construct(
        public ArchitectureConfiguration $configuration,
        public array $warnings = [],
    ) {}

    public function warnings(): array
    {
        return $this->warnings;
    }
}
