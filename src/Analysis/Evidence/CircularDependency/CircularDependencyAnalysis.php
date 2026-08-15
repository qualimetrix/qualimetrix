<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/**
 * Owns prepared circular-dependency evidence for one analysis run.
 *
 * @qmx-ignore design.data-class -- Run-scoped state holder intentionally exposes preparation and read access around private circular-dependency evidence.
 */
final class CircularDependencyAnalysis implements CircularDependencyPreparationInterface
{
    /** @var list<Cycle> */
    private array $cycles = [];

    public function __construct(
        private readonly CircularDependencyDetector $detector,
    ) {}

    public function prepare(DependencyGraphInterface $graph): void
    {
        $this->reset();
        $this->cycles = $this->detector->detect($graph);
    }

    public function reset(): void
    {
        $this->cycles = [];
    }

    /**
     * @return list<Cycle>
     */
    public function all(): array
    {
        return $this->cycles;
    }

    /**
     * Replaces prepared evidence for an internal fixture.
     *
     * @param list<Cycle> $cycles
     */
    public function replace(array $cycles): void
    {
        $this->cycles = $cycles;
    }
}
