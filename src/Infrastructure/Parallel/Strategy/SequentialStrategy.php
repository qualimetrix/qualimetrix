<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

use Qualimetrix\Analysis\Collection\Strategy\ExecutionStrategyInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
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
    public function execute(array $files, callable $processor, bool $canParallelize = true): array // @phpstan-ignore method.childReturnType
    {
        $results = [];
        $profiler = ProfilerHolder::get();

        foreach ($files as $file) {
            $profiler->start('collection.file', 'collection');
            $results[] = $processor($file);
            $profiler->stop('collection.file');
        }

        return $results;
    }
}
