<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Preparation;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Measurement\CompletedMeasurements;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** The measured state and source observations produced by collection. */
final readonly class CollectionResult
{
    /**
     * @param list<RelativePath> $analyzedFiles
     * @param list<FileProcessingResult> $failures
     * @param array<string, list<Suppression>> $suppressions
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides
     * @param array<string, list<ThresholdDiagnostic>> $thresholdDiagnostics
     * @param list<SplFileInfo> $files
     *
     * @qmx-threshold code-smell.long-parameter-list warning=10 error=10 -- Independent immutable fields describe one completed collection observation; keeping their names together prevents mismatched phase facts. The finite arity boundary exposes added fields.
     */
    public function __construct(
        public MetricRepositoryInterface $repository,
        public DependencyGraphInterface $graph,
        public CompletedMeasurements $measurements,
        public array $analyzedFiles,
        public array $failures,
        public array $suppressions,
        public array $thresholdOverrides,
        public array $thresholdDiagnostics,
        public array $files,
    ) {}
}
