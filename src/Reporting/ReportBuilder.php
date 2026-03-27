<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use InvalidArgumentException;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

/**
 * Builder for creating Report instances.
 */
final class ReportBuilder
{
    /**
     * @var list<Violation>
     */
    private array $violations = [];

    private int $filesAnalyzed = 0;
    private int $filesSkipped = 0;
    private float $duration = 0.0;
    private ?MetricRepositoryInterface $metrics = null;
    private ?NamespaceTree $namespaceTree = null;

    /**
     * Creates a new builder instance.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Adds a single violation.
     */
    public function addViolation(Violation $violation): self
    {
        $this->violations[] = $violation;

        return $this;
    }

    /**
     * Adds multiple violations.
     *
     * @param iterable<Violation> $violations
     */
    public function addViolations(iterable $violations): self
    {
        foreach ($violations as $violation) {
            $this->violations[] = $violation;
        }

        return $this;
    }

    /**
     * Sets the number of analyzed files.
     *
     * @throws InvalidArgumentException if count is negative
     */
    public function filesAnalyzed(int $count): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Files analyzed count must be non-negative');
        }

        $this->filesAnalyzed = $count;

        return $this;
    }

    /**
     * Sets the number of skipped files.
     *
     * @throws InvalidArgumentException if count is negative
     */
    public function filesSkipped(int $count): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Files skipped count must be non-negative');
        }

        $this->filesSkipped = $count;

        return $this;
    }

    /**
     * Sets the analysis duration in seconds.
     *
     * @throws InvalidArgumentException if duration is negative
     */
    public function duration(float $seconds): self
    {
        if ($seconds < 0.0) {
            throw new InvalidArgumentException('Duration must be non-negative');
        }

        $this->duration = $seconds;

        return $this;
    }

    /**
     * Sets the metric repository for raw metric export.
     */
    public function metrics(MetricRepositoryInterface $metrics): self
    {
        $this->metrics = $metrics;

        return $this;
    }

    /**
     * Sets the canonical namespace tree from the analysis pipeline.
     */
    public function namespaceTree(?NamespaceTree $tree): self
    {
        $this->namespaceTree = $tree;

        return $this;
    }

    /**
     * Builds the Report instance.
     */
    public function build(): Report
    {
        $errorCount = 0;
        $warningCount = 0;

        foreach ($this->violations as $violation) {
            match ($violation->severity) {
                Severity::Error => $errorCount++,
                Severity::Warning => $warningCount++,
            };
        }

        return new Report(
            violations: $this->violations,
            filesAnalyzed: $this->filesAnalyzed,
            filesSkipped: $this->filesSkipped,
            duration: $this->duration,
            errorCount: $errorCount,
            warningCount: $warningCount,
            metrics: $this->metrics,
            namespaceTree: $this->namespaceTree,
        );
    }
}
