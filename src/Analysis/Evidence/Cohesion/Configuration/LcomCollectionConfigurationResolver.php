<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion\Configuration;

use LogicException;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationResolverInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;

final class LcomCollectionConfigurationResolver implements LcomCollectionConfigurationResolverInterface
{
    public function resolve(FindingConfiguration $configuration): LcomCollectionConfiguration
    {
        $snapshot = $configuration->resolvedOptions
            ?? throw new LogicException('LCOM collection configuration requires resolved rule options.');
        $options = $snapshot->for('cohesion.lcom');
        if (!$options instanceof LcomOptions) {
            throw new LogicException('The LCOM producer requires LcomOptions.');
        }
        return new LcomCollectionConfiguration($options->excludeMethods ?? []);
    }
}
