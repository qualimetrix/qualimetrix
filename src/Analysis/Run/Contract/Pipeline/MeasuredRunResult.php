<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;

final readonly class MeasuredRunResult
{
    public function __construct(
        public MetricRepositoryInterface $repository,
        public AnalysisCoverage $coverage,
        public ?NamespaceTree $namespaceTree,
        public ?ProjectScopeMeasurement $projectScope,
        public float $duration,
    ) {}

    public function merge(self $other): self
    {
        return new self(
            repository: $this->repository->mergedWith($other->repository) ?? $this->repository,
            coverage: $this->coverage->merge($other->coverage),
            namespaceTree: $this->namespaceTree ?? $other->namespaceTree,
            projectScope: $this->projectScope ?? $other->projectScope,
            duration: max($this->duration, $other->duration),
        );
    }
}
