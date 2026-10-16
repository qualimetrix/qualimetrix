<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\MixedSpelling;

/** Dependency graph and the terminal state of every discovered input file. */
final readonly class DependencyGraphAnalysisResult
{
    public function __construct(
        public DependencyGraphInterface $graph,
        public AnalysisCoverage $coverage,
        /** @var list<MixedSpelling> */
        public array $mixedSpellings,
    ) {}
}
