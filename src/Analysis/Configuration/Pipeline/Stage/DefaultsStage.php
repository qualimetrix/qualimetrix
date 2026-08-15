<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;

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

    public function apply(ConfigurationResolutionRequest $request): ConfigurationLayer
    {
        return new ConfigurationLayer('defaults', []);
    }
}
