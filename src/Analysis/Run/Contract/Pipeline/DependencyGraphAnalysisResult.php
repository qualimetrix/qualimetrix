<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/** Dependency graph and the terminal state of every discovered input file. */
final readonly class DependencyGraphAnalysisResult
{
    public function __construct(
        public DependencyGraphInterface $graph,
        public AnalysisCoverage $coverage,
    ) {}
}
