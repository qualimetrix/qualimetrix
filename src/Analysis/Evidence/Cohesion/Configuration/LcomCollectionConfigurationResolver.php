<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion\Configuration;

use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;

final class LcomCollectionConfigurationResolver implements LcomCollectionConfigurationResolverInterface
{
    public function resolve(FindingConfiguration $configuration): LcomCollectionConfiguration
    {
        $options = array_replace_recursive($configuration->ruleOptions->rules, $configuration->cliOverrides->options);
        $value = $options['cohesion.lcom']['exclude_methods'] ?? $options['cohesion.lcom']['excludeMethods'] ?? [];
        $methods = \is_string($value) ? array_map('trim', explode(',', $value)) : (\is_array($value) ? array_values($value) : []);

        return new LcomCollectionConfiguration(array_values(array_filter($methods, is_string(...))));
    }
}
