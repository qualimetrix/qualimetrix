<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Preparation;

use LogicException;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailureKind;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** The path and scope facts established by one discovery pass. */
final readonly class DiscoveryResult
{
    /**
     * @param list<SplFileInfo> $files
     * @param list<RelativePath> $eligiblePaths
     * @param list<RelativePath> $generatedExcludedFiles
     * @param array<string, RelativePath> $publishedByInput
     * @param list<AnalysisFailure> $skippedFailures
     * @param list<RelativePath> $namedExcluded
     */
    public function __construct(
        public ProjectScopeMeasurement $measuredScope,
        public array $files,
        public array $eligiblePaths,
        public array $generatedExcludedFiles,
        public array $publishedByInput,
        public array $skippedFailures,
        public array $namedExcluded,
    ) {}

    /**
     * @param list<RelativePath> $analyzedFiles
     * @param list<\Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult> $collectionFailures
     * @param list<AnalysisFailure> $inspectionFailures
     */
    public function coverage(array $analyzedFiles, array $collectionFailures, array $inspectionFailures): AnalysisCoverage
    {
        $failures = array_map(
            static function ($failure): AnalysisFailure {
                return new AnalysisFailure(
                    $failure->filePath,
                    match ($failure->failureKind()) {
                        FileProcessingFailureKind::Parse => AnalysisFailureKind::Parse,
                        FileProcessingFailureKind::UnreadableFile => AnalysisFailureKind::UnreadableFile,
                        FileProcessingFailureKind::Processing => AnalysisFailureKind::Processing,
                    },
                    $failure->error(),
                );
            },
            $collectionFailures,
        );

        $collectionFailurePaths = [];
        foreach ($failures as $failure) {
            $collectionFailurePaths[$failure->path->value()] = true;
        }

        $lateFailures = [];
        foreach ($inspectionFailures as $failure) {
            $key = $failure->path->value();
            if (!isset($collectionFailurePaths[$key])) {
                $lateFailures[$key] ??= $failure;
            }
        }

        $analyzed = array_values(array_filter(
            $analyzedFiles,
            static fn(RelativePath $path): bool => !isset($lateFailures[$path->value()]),
        ));

        $coverage = new AnalysisCoverage(
            $analyzed,
            $this->generatedExcludedFiles,
            [...$failures, ...array_values($lateFailures)],
            $this->namedExcluded,
        );

        // A skipped entry is a discovered path with a terminal state, so it
        // belongs on both sides of the invariant below — not only in the
        // coverage it is recorded in.
        $skippedPaths = [];
        foreach ($this->skippedFailures as $skip) {
            $skippedPaths[] = $skip->path;
            $coverage = $coverage->withSkipped($skip->path, $skip->kind, $skip->message);
        }

        $this->assertCoverageMatchesDiscovery($coverage, [...$this->eligiblePaths, ...$skippedPaths]);

        return $coverage;
    }

    /** @param list<RelativePath> $eligiblePaths */
    private function assertCoverageMatchesDiscovery(AnalysisCoverage $coverage, array $eligiblePaths): void
    {
        $expected = array_map(static fn(RelativePath $path): string => $path->value(), $eligiblePaths);
        sort($expected);

        $actual = array_map(
            static fn(RelativePath $path): string => $path->value(),
            $coverage->analyzedFiles,
        );
        foreach ($coverage->failures as $failure) {
            $actual[] = $failure->path->value();
        }
        sort($actual);

        if ($actual !== $expected) {
            throw new LogicException('Collection terminal states do not match the discovered analysis paths');
        }
    }
}
