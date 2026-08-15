<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion\Runtime;

use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;

final class LcomCollectionConfigurationStore implements LcomCollectionConfigurationStoreInterface
{
    private LcomCollectionConfiguration $configuration;

    /** @param iterable<LcomCollectionConfigurableInterface> $collectors */
    public function __construct(private readonly iterable $collectors = [])
    {
        $this->configuration = LcomCollectionConfiguration::defaults();
    }

    public function replace(LcomCollectionConfiguration $configuration): void
    {
        $this->configuration = $configuration;
        foreach ($this->collectors as $collector) {
            $collector->applyLcomCollectionConfiguration($configuration);
        }
    }

    public function current(): LcomCollectionConfiguration
    {
        return $this->configuration;
    }
    public function reset(): void
    {
        $this->replace(LcomCollectionConfiguration::defaults());
    }
}
