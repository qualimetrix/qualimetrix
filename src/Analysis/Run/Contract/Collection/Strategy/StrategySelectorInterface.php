<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Collection\Strategy;

use Qualimetrix\Core\Path\AbsolutePath;

/**
 * Interface for strategy selection.
 *
 * Allows lazy selection of execution strategy based on configuration.
 * This is needed because configuration may not be available at service
 * construction time (e.g., CLI arguments are parsed after DI container is built).
 */
interface StrategySelectorInterface
{
    /**
     * Selects the best available execution strategy.
     *
     * This method should be called lazily (when strategy is needed),
     * not at service construction time.
     */
    public function select(AbsolutePath $projectRoot): ExecutionStrategyInterface;
}
