<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;

/**
 * Value Object representing the analysis report.
 */
final readonly class Report
{
    /**
     * @param list<Violation> $violations
     * @param array<string, HealthScore> $healthScores
     * @param list<WorstOffender> $worstNamespaces
     * @param list<WorstOffender> $worstClasses
     */
    public function __construct(
        public array $violations,
        public int $filesAnalyzed,
        public int $filesSkipped,
        public float $duration,
        public int $errorCount,
        public int $warningCount,
        public ?MetricRepositoryInterface $metrics = null,
        public array $healthScores = [],
        public array $worstNamespaces = [],
        public array $worstClasses = [],
        public int $techDebtMinutes = 0,
    ) {}

    /**
     * Checks if report has no violations.
     */
    public function isEmpty(): bool
    {
        return $this->violations === [];
    }

    /**
     * Returns total number of violations.
     */
    public function getTotalViolations(): int
    {
        return \count($this->violations);
    }

    /**
     * Returns violations filtered by severity.
     *
     * @return list<Violation>
     */
    public function getViolationsBySeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn(Violation $v): bool => $v->severity === $severity,
        ));
    }

    /**
     * Returns the highest exit code based on violations.
     * 0 if no violations, otherwise max of severity exit codes.
     */
    public function getExitCode(): int
    {
        if ($this->isEmpty()) {
            return 0;
        }

        $exitCode = 0;
        foreach ($this->violations as $violation) {
            $exitCode = max($exitCode, $violation->severity->getExitCode());
        }

        return $exitCode;
    }
}
