<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Rule;

use AiMessDetector\Core\Dependency\CycleInterface;
use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;

final readonly class AnalysisContext
{
    /**
     * @param array<string, mixed> $ruleOptions
     * @param list<CycleInterface> $cycles Detected circular dependency cycles
     */
    public function __construct(
        public MetricRepositoryInterface $metrics,
        public array $ruleOptions = [],
        public ?DependencyGraphInterface $dependencyGraph = null,
        public array $cycles = [],
    ) {}

    /**
     * Gets options for a specific rule.
     *
     * @return array<string, mixed>
     */
    public function getOptionsForRule(string $ruleName): array
    {
        /** @var array<string, mixed> */
        return $this->ruleOptions[$ruleName] ?? [];
    }
}
