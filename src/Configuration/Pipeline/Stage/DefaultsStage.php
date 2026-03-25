<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline\Stage;

use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationLayer;

/**
 * Default configuration values (priority: 0).
 *
 * Applied first, can be overridden by all other stages.
 */
final class DefaultsStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 0;

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'defaults';
    }

    public function apply(ConfigurationContext $context): ConfigurationLayer
    {
        return new ConfigurationLayer('defaults', [
            'paths' => ['.'],
            'excludes' => ['vendor', 'node_modules', '.git'],
            'cache.dir' => '.qmx-cache',
            'cache.enabled' => true,
            'format' => 'summary',
            'namespace.strategy' => 'chain',
            'project_root' => $context->workingDirectory,
        ]);
    }
}
