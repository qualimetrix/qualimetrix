<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Runtime;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurationStoreInterface;

final class CollectorRuntimeConfigurationStore implements CollectorRuntimeConfigurationStoreInterface
{
    private CollectorRuntimeConfiguration $configuration;

    /** @param iterable<CollectorRuntimeConfigurableInterface> $collectors */
    public function __construct(private readonly iterable $collectors = [])
    {
        $this->configuration = CollectorRuntimeConfiguration::empty();
    }

    public function current(): CollectorRuntimeConfiguration
    {
        return $this->configuration;
    }

    public function replace(CollectorRuntimeConfiguration $configuration): void
    {
        $this->configuration = $configuration;
        foreach ($this->collectors as $collector) {
            $collector->applyRuntimeConfiguration($configuration);
        }
    }

    public function reset(): void
    {
        $this->replace(CollectorRuntimeConfiguration::empty());
    }
}
