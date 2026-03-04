<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Parallel\Strategy;

use AiMessDetector\Analysis\Collection\Strategy\ExecutionStrategyInterface;
use SplFileInfo;

/**
 * Sequential execution strategy.
 *
 * Fallback strategy that processes files sequentially in the same process.
 * Always available on all systems.
 */
final class SequentialStrategy implements ExecutionStrategyInterface
{
    public function isParallelAvailable(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Execute processing for files sequentially.
     *
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): mixed $processor
     *
     * @return list<mixed>
     */
    public function execute(array $files, callable $processor, bool $canParallelize = true): array
    {
        $results = [];

        foreach ($files as $file) {
            $results[] = $processor($file);
        }

        return $results;
    }
}
