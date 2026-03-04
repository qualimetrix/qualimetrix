<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Rule;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;

final readonly class AnalysisContext
{
    /**
     * @param array<string, mixed> $ruleOptions
     * @param array<string, mixed> $additionalData Additional analysis data (e.g., cycles, violations, etc.)
     */
    public function __construct(
        public MetricRepositoryInterface $metrics,
        public array $ruleOptions = [],
        public ?DependencyGraphInterface $dependencyGraph = null,
        public array $additionalData = [],
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

    /**
     * Gets additional data by key.
     */
    public function getAdditionalData(string $key): mixed
    {
        return $this->additionalData[$key] ?? null;
    }
}
