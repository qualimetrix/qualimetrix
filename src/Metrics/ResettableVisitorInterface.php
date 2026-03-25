<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics;

/**
 * Interface for visitors that need to reset state between files.
 */
interface ResettableVisitorInterface
{
    /**
     * Resets visitor state for processing a new file.
     */
    public function reset(): void;
}
