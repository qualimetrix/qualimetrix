<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline\Stage;

use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigSchema;
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
            ConfigSchema::PATHS => ['.'],
            ConfigSchema::EXCLUDES => ['vendor', 'node_modules', '.git'],
            ConfigSchema::CACHE_DIR => AnalysisConfiguration::DEFAULT_CACHE_DIR,
            ConfigSchema::CACHE_ENABLED => true,
            ConfigSchema::FORMAT => AnalysisConfiguration::DEFAULT_FORMAT,
            ConfigSchema::NAMESPACE_STRATEGY => AnalysisConfiguration::DEFAULT_NAMESPACE_STRATEGY,
            ConfigSchema::PROJECT_ROOT => $context->workingDirectory,
        ]);
    }
}
