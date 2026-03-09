<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection;

use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use SplFileInfo;

/**
 * Interface for orchestrating the collection phase.
 *
 * Coordinates processing of multiple files, either sequentially or in parallel,
 * and registers collected metrics in the repository. Metrics and dependencies
 * are collected in a single AST traversal per file.
 */
interface CollectionOrchestratorInterface
{
    /**
     * Collects metrics and dependencies from all files.
     *
     * Both metrics and dependencies are collected in a single AST traversal per file.
     *
     * @param list<SplFileInfo> $files Files to process
     * @param MetricRepositoryInterface $repository Repository to store metrics
     *
     * @return CollectionPhaseOutput Result summary + dependencies (separated by lifecycle)
     */
    public function collect(
        array $files,
        MetricRepositoryInterface $repository,
    ): CollectionPhaseOutput;
}
