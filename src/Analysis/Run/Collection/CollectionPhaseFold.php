<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Collection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Accumulates per-file results, in arrival order, into the collection phase
 * output. Inline controls are keyed by file path and a file without any is
 * left out of their maps.
 */
final class CollectionPhaseFold
{
    /** @var list<RelativePath> */
    private array $analyzedFiles = [];

    /** @var list<FileProcessingResult> */
    private array $failures = [];

    /** @var list<Dependency> */
    private array $dependencies = [];

    /** @var array<string, list<Suppression>> */
    private array $suppressions = [];

    /** @var array<string, list<ThresholdOverride>> */
    private array $thresholdOverrides = [];

    /** @var array<string, list<ThresholdDiagnostic>> */
    private array $thresholdDiagnostics = [];

    public function absorb(FileProcessingResult $result): void
    {
        if (!$result->isSuccessful()) {
            $this->failures[] = $result;

            return;
        }

        $filePathKey = $result->filePath->value();
        $this->analyzedFiles[] = $result->filePath;
        array_push($this->dependencies, ...$result->dependencies());
        if ($result->suppressions() !== []) {
            $this->suppressions[$filePathKey] = $result->suppressions();
        }
        if ($result->thresholdOverrides() !== []) {
            $this->thresholdOverrides[$filePathKey] = $result->thresholdOverrides();
        }
        if ($result->thresholdDiagnostics() !== []) {
            $this->thresholdDiagnostics[$filePathKey] = $result->thresholdDiagnostics();
        }
    }

    public function output(): CollectionPhaseOutput
    {
        return new CollectionPhaseOutput(
            $this->analyzedFiles,
            $this->failures,
            $this->suppressions,
            $this->thresholdOverrides,
            $this->thresholdDiagnostics,
            $this->dependencies,
        );
    }
}
