<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\ExecutionStrategyInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use SplFileInfo;

/**
 * Sequential execution strategy.
 *
 * Fallback strategy that processes files sequentially in the same process.
 * Always available on all systems.
 */
final class SequentialStrategy implements ExecutionStrategyInterface
{
    public function __construct(private readonly ProfilerInterface $profiler) {}
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Execute processing for files sequentially.
     *
     * @param list<SplFileInfo> $files
     * @param callable(SplFileInfo): FileProcessingResult $processor
     *
     * @return list<FileProcessingResult>
     */
    public function execute(array $files, callable $processor): array
    {
        $results = [];
        $profiler = $this->profiler;

        foreach ($files as $file) {
            $profiler->start('collection.file', 'collection');
            try {
                $results[] = $processor($file);
            } finally {
                $profiler->stop('collection.file');
            }
        }

        return $results;
    }
}
