<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

/** Explicit, analysis-layer-independent coverage projection for all formatters. */
final readonly class ReportCoverage
{
    /** @param list<CoverageFailure> $failures */
    public function __construct(
        public int $discovered,
        public int $analyzed,
        public int $generatedExcluded,
        public int $failed,
        public array $failures = [],
    ) {}

    public function isComplete(): bool
    {
        return $this->failed === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'complete' => $this->isComplete(),
            'discovered' => $this->discovered,
            'analyzed' => $this->analyzed,
            'generatedExcluded' => $this->generatedExcluded,
            'failed' => $this->failed,
            'failures' => array_map(
                static fn(CoverageFailure $failure): array => [
                    'path' => $failure->path,
                    'kind' => $failure->kind,
                    'message' => $failure->message,
                ],
                $this->failures,
            ),
        ];
    }
}
