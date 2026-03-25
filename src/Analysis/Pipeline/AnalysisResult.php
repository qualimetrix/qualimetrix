<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

final readonly class AnalysisResult
{
    /**
     * @param list<Violation> $violations
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags
     */
    public function __construct(
        public array $violations,
        public int $filesAnalyzed,
        public int $filesSkipped,
        public float $duration,
        public MetricRepositoryInterface $metrics,
        public array $suppressions = [],
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity === Severity::Warning) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns exit code based on violations.
     * 0 - no violations
     * 1 - only warnings
     * 2 - errors present
     */
    public function getExitCode(): int
    {
        if ($this->hasErrors()) {
            return 2;
        }

        if ($this->hasWarnings()) {
            return 1;
        }

        return 0;
    }

    /**
     * Merges results for parallel processing.
     */
    public function merge(self $other): self
    {
        // Merge metrics if both are InMemoryMetricRepository
        $mergedMetrics = $this->metrics;
        if (
            $this->metrics instanceof InMemoryMetricRepository
            && $other->metrics instanceof InMemoryMetricRepository
        ) {
            $mergedMetrics = $this->metrics->mergeWith($other->metrics);
        }

        $mergedSuppressions = $this->suppressions;
        foreach ($other->suppressions as $file => $list) {
            $mergedSuppressions[$file] = array_merge($mergedSuppressions[$file] ?? [], $list);
        }

        return new self(
            violations: [...$this->violations, ...$other->violations],
            filesAnalyzed: $this->filesAnalyzed + $other->filesAnalyzed,
            filesSkipped: $this->filesSkipped + $other->filesSkipped,
            duration: max($this->duration, $other->duration),
            metrics: $mergedMetrics,
            suppressions: $mergedSuppressions,
        );
    }

    /**
     * Returns violations sorted by file and line.
     *
     * @return list<Violation>
     */
    public function getSortedViolations(): array
    {
        $sorted = $this->violations;

        usort($sorted, static function (Violation $a, Violation $b): int {
            $fileCompare = strcmp($a->location->file, $b->location->file);
            if ($fileCompare !== 0) {
                return $fileCompare;
            }

            return ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
        });

        return $sorted;
    }

    /**
     * @return array{errors: int, warnings: int}
     */
    public function getViolationCountBySeverity(): array
    {
        $errors = 0;
        $warnings = 0;

        foreach ($this->violations as $violation) {
            if ($violation->severity === Severity::Error) {
                $errors++;
            } else {
                $warnings++;
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
