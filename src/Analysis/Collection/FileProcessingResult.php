<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use LogicException;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;

/** Serializable terminal state of processing one file. */
final readonly class FileProcessingResult
{
    private function __construct(
        public RelativePath $filePath,
        private ?CollectedFileData $data,
        private ?FileProcessingFailure $failure,
    ) {
        if (($this->data === null) === ($this->failure === null)) {
            throw new LogicException('File processing result must contain exactly one terminal state');
        }
    }

    /**
     * @param list<\Qualimetrix\Core\Metric\CallableWithMetrics> $callableMetrics
     * @param array<string, array{subject: \Qualimetrix\Core\Symbol\MetricSubject, metrics: MetricBag, line: int}> $classMetrics
     * @param array<string, array{symbolPath: \Qualimetrix\Core\Symbol\SymbolPath, metrics: MetricBag, line: int}> $namespaceMetrics
     * @param list<\Qualimetrix\Core\Dependency\Dependency> $dependencies
     * @param list<\Qualimetrix\Core\Suppression\Suppression> $suppressions
     * @param list<\Qualimetrix\Core\Suppression\ThresholdOverride> $thresholdOverrides
     * @param list<\Qualimetrix\Core\Suppression\ThresholdDiagnostic> $thresholdDiagnostics
     */
    public static function success(
        RelativePath $filePath,
        MetricBag $fileBag,
        array $callableMetrics = [],
        array $classMetrics = [],
        array $namespaceMetrics = [],
        array $dependencies = [],
        array $suppressions = [],
        array $thresholdOverrides = [],
        array $thresholdDiagnostics = [],
    ): self {
        return new self(
            $filePath,
            new CollectedFileData(
                $fileBag,
                $callableMetrics,
                $classMetrics,
                $namespaceMetrics,
                $dependencies,
                $suppressions,
                $thresholdOverrides,
                $thresholdDiagnostics,
            ),
            null,
        );
    }

    public static function failure(
        RelativePath $filePath,
        string $error,
        FileProcessingFailureKind $kind = FileProcessingFailureKind::Processing,
    ): self {
        return new self($filePath, null, new FileProcessingFailure($kind, $error));
    }

    public function isSuccessful(): bool
    {
        return $this->data !== null;
    }

    public function collectedData(): CollectedFileData
    {
        return $this->data ?? throw new LogicException('Failed file processing has no collected data');
    }

    public function processingFailure(): FileProcessingFailure
    {
        return $this->failure ?? throw new LogicException('Successful file processing has no failure');
    }
}
