<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;

/**
 * CLI arguments and options (priority: 30).
 *
 * Has highest priority - overrides all other stages.
 */
final class CliStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 30;

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'cli';
    }

    public function apply(ConfigurationResolutionRequest $request): ?ConfigurationLayer
    {
        if ($request->cliValues === []) {
            return null;
        }

        return new ConfigurationLayer('cli', $request->cliValues);
    }
}
