<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Collection;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Serializable terminal state of processing one file. */
final readonly class FileProcessingResult
{
    private function __construct(
        public RelativePath $filePath,
        private ?SuccessfulFileProcessing $success,
        private ?FileProcessingFailureKind $failureKind,
        private ?string $error,
    ) {
        $completeSuccess = $this->success !== null && $this->failureKind === null && $this->error === null;
        $completeFailure = $this->success === null && $this->failureKind !== null && $this->error !== null;
        if (!$completeSuccess && !$completeFailure) {
            throw new LogicException('File processing result must contain exactly one terminal state');
        }
    }

    public static function success(RelativePath $filePath, SuccessfulFileProcessing $payload): self
    {
        return new self($filePath, $payload, null, null);
    }

    public static function failure(
        RelativePath $filePath,
        string $error,
        FileProcessingFailureKind $kind = FileProcessingFailureKind::Processing,
    ): self {
        return new self($filePath, null, $kind, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->success !== null;
    }

    public function fileBag(): MetricBag
    {
        return $this->successfulPayload()->fileBag;
    }

    /** @return list<CallableWithMetrics> */
    public function callableMetrics(): array
    {
        return $this->successfulPayload()->callableMetrics;
    }

    /** @return array<string, array{subject: MetricSubject, metrics: MetricBag, line: int}> */
    public function classMetrics(): array
    {
        return $this->successfulPayload()->classMetrics;
    }

    /** @return array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}> */
    public function namespaceMetrics(): array
    {
        return $this->successfulPayload()->namespaceMetrics;
    }

    /** @return list<\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency> */
    public function dependencies(): array
    {
        return $this->successfulPayload()->dependencies;
    }

    /** @return list<Suppression> */
    public function suppressions(): array
    {
        return $this->successfulPayload()->suppressions;
    }

    /** @return list<ThresholdOverride> */
    public function thresholdOverrides(): array
    {
        return $this->successfulPayload()->thresholdOverrides;
    }

    /** @return list<ThresholdDiagnostic> */
    public function thresholdDiagnostics(): array
    {
        return $this->successfulPayload()->thresholdDiagnostics;
    }

    public function failureKind(): FileProcessingFailureKind
    {
        return $this->failureKind ?? throw new LogicException('Successful file processing has no failure');
    }

    public function error(): string
    {
        return $this->error ?? throw new LogicException('Successful file processing has no failure');
    }

    private function successfulPayload(): SuccessfulFileProcessing
    {
        return $this->success ?? throw new LogicException('Failed file processing has no collected data');
    }
}
