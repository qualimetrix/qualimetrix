<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Violation\SymbolPath;

/**
 * Result of processing a single file.
 *
 * Serializable structure for passing between processes in parallel collection.
 * Contains either successful metrics or error information.
 */
final class FileProcessingResult
{
    /**
     * @param string $filePath Path to the processed file
     * @param bool $success Whether processing succeeded
     * @param MetricBag|null $fileBag File-level metrics (null on failure)
     * @param array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}> $methodMetrics
     * @param array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}> $classMetrics
     * @param string|null $error Error message (null on success)
     * @param list<Dependency> $dependencies Dependencies collected from the file
     */
    private function __construct(
        public readonly string $filePath,
        public readonly bool $success,
        public readonly ?MetricBag $fileBag,
        public readonly array $methodMetrics,
        public readonly array $classMetrics,
        public readonly ?string $error,
        public readonly array $dependencies = [],
    ) {}

    /**
     * Creates a successful result.
     *
     * @param string $filePath Path to the processed file
     * @param MetricBag $fileBag File-level metrics
     * @param array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}> $methodMetrics
     * @param array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}> $classMetrics
     * @param list<Dependency> $dependencies Dependencies collected from the file
     */
    public static function success(
        string $filePath,
        MetricBag $fileBag,
        array $methodMetrics = [],
        array $classMetrics = [],
        array $dependencies = [],
    ): self {
        return new self(
            filePath: $filePath,
            success: true,
            fileBag: $fileBag,
            methodMetrics: $methodMetrics,
            classMetrics: $classMetrics,
            error: null,
            dependencies: $dependencies,
        );
    }

    /**
     * Creates a failure result.
     *
     * @param string $filePath Path to the file that failed
     * @param string $error Error message
     */
    public static function failure(string $filePath, string $error): self
    {
        return new self(
            filePath: $filePath,
            success: false,
            fileBag: null,
            methodMetrics: [],
            classMetrics: [],
            error: $error,
        );
    }
}
