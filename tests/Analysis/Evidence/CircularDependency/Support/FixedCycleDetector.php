<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support;

use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\CircularDependency\Cycle;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/**
 * A detector that answers with the cycles a test sets, whatever the graph.
 *
 * Lets a rule test hand `CircularDependencyAnalysis` exact cycles through its
 * one real entry point, `prepare()`, instead of a mutator on the product class.
 */
final class FixedCycleDetector extends CircularDependencyDetector
{
    /** @var list<Cycle> */
    public array $cycles = [];

    public function detect(DependencyGraphInterface $graph): array
    {
        return $this->cycles;
    }
}
