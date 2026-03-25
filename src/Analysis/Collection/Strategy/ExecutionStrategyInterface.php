<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Strategy;

use Qualimetrix\Analysis\Collection\FileProcessingResult;
use SplFileInfo;

/**
 * Strategy for executing file processing.
 *
 * Abstracts the execution model (sequential vs parallel) from the orchestrator.
 * Different implementations may use different parallelization mechanisms:
 * - SequentialStrategy: processes files one by one
 * - PcntlForkStrategy: uses pcntl_fork for parallel processing (Unix only)
 */
interface ExecutionStrategyInterface
{
    /**
     * Executes processor for each file, potentially in parallel.
     *
     * @param list<SplFileInfo> $files Files to process
     * @param callable(SplFileInfo): FileProcessingResult $processor Function to process each file
     * @param bool $canParallelize Whether parallel execution is allowed for this batch
     *
     * @return list<FileProcessingResult> Results from all files
     */
    public function execute(array $files, callable $processor, bool $canParallelize = true): array;

    /**
     * Returns whether parallel execution is available on this system.
     */
    public function isParallelAvailable(): bool;
}
