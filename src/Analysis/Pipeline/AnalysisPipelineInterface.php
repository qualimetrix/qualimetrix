<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Pipeline;

use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;

/**
 * Main entry point for code analysis.
 *
 * Coordinates the analysis pipeline:
 * 1. Discovery - Find PHP files to analyze
 * 2. Collection - Parse files and collect metrics (parallel or sequential)
 * 3. Build dependency graph
 * 4. Aggregation - Aggregate metrics at class/namespace/project level
 * 5. Global collectors - Compute cross-file metrics (coupling, distance, etc.)
 * 6. Rule execution - Run rules against collected metrics
 * 7. Return analysis result
 */
interface AnalysisPipelineInterface
{
    /**
     * Analyze the given paths.
     *
     * @param string|list<string> $paths Single path or list of paths to analyze
     * @param FileDiscoveryInterface|null $customFileDiscovery Custom file discovery strategy (e.g., for Git scope)
     */
    public function analyze(string|array $paths, ?FileDiscoveryInterface $customFileDiscovery = null): AnalysisResult;
}
