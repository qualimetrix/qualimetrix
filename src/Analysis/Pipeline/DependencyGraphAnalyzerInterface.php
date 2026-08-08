<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Qualimetrix\Core\Path\AbsolutePath;

/** Runs the complete discovery-to-dependency-graph analysis lifecycle. */
interface DependencyGraphAnalyzerInterface
{
    /**
     * @param list<AbsolutePath> $paths
     */
    public function analyze(array $paths, AbsolutePath $projectRoot): DependencyGraphAnalysisResult;
}
