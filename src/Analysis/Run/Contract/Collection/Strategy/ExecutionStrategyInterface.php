<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Collection\Strategy;

use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use SplFileInfo;

/**
 * Strategy for executing file processing.
 *
 * Abstracts the execution model (sequential vs parallel) from the orchestrator.
 * Sequential collection runs in-process; parallel collection uses separate
 * processes. Actual worker startup is judged during execution, not selection.
 */
interface ExecutionStrategyInterface
{
    /**
     * Executes processor for each file, potentially in parallel.
     *
     * @param list<SplFileInfo> $files Files to process
     * @param callable(SplFileInfo): FileProcessingResult $processor Function to process each file
     *
     * @return list<FileProcessingResult> Results from all files
     */
    public function execute(array $files, callable $processor): array;
}
