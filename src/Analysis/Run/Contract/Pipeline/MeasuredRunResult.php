<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;

final readonly class MeasuredRunResult
{
    public function __construct(
        public MetricRepositoryInterface $repository,
        public AnalysisCoverage $coverage,
        public ?NamespaceTree $namespaceTree,
        public ?ProjectScopeMeasurement $projectScope,
        public float $duration,
        public SubjectCoverageFacts $subjectCoverage,
    ) {}

    public function merge(self $other): self
    {
        $coverage = $this->coverage->merge($other->coverage);
        $projectScope = $this->projectScope ?? $other->projectScope;

        return new self(
            repository: $this->repository->mergedWith($other->repository) ?? $this->repository,
            coverage: $coverage,
            namespaceTree: $this->namespaceTree ?? $other->namespaceTree,
            projectScope: $projectScope,
            duration: max($this->duration, $other->duration),
            subjectCoverage: SubjectCoverageFacts::fromMeasured(
                $projectScope?->judgement() ?? new ProjectScopeJudgement(),
                $coverage->analyzedFiles,
                array_map(static fn(AnalysisFailure $failure) => $failure->path, $coverage->failures),
            ),
        );
    }
}
