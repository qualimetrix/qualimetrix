<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;

/** Dependency graph and the terminal state of every discovered input file. */
final readonly class DependencyGraphAnalysisResult
{
    public function __construct(
        public DependencyGraphInterface $graph,
        public AnalysisCoverage $coverage,
    ) {}
}
