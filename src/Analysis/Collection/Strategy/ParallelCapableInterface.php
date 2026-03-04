<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Strategy;

/**
 * Interface for execution strategies that support parallel execution.
 *
 * Allows configuration of worker count for parallel processing.
 */
interface ParallelCapableInterface
{
    /**
     * Sets the number of workers for parallel execution.
     *
     * @param int $count Number of workers (must be >= 1)
     */
    public function setWorkerCount(int $count): void;

    /**
     * Returns the configured number of workers.
     */
    public function getWorkerCount(): int;
}
