<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use LogicException;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;

/**
 * Owns prepared circular-dependency evidence for one analysis run.
 */
final class CircularDependencyAnalysis implements CircularDependencyPreparationInterface
{
    /** @var list<Cycle> */
    private array $cycles = [];

    /**
     * @param RuleConfigurationInterface $ruleConfiguration the run's resolved rule options, read for
     *                                                      `ignore-relations` at preparation
     */
    public function __construct(
        private readonly CircularDependencyDetector $detector,
        private readonly RuleConfigurationInterface $ruleConfiguration,
    ) {}

    public function prepare(DependencyGraphInterface $graph): void
    {
        $this->reset();
        $this->cycles = $this->detector->admitting($this->edgeFilter())->detect($graph);
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

    private function edgeFilter(): CycleEdgeFilter
    {
        $options = $this->ruleConfiguration->resolvedOptions()->for(self::PRODUCER_RULE_NAME);
        if (!$options instanceof CircularDependencyOptions) {
            throw new LogicException('Circular-dependency preparation requires CircularDependencyOptions.');
        }

        return CycleEdgeFilter::ignoring($options->ignoredRelations);
    }
}
