<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Builder for creating Report instances.
 */
final class ReportBuilder
{
    /**
     * @var list<Finding>
     */
    private array $findings = [];

    private int $filesAnalyzed = 0;
    private int $filesSkipped = 0;
    private float $duration = 0.0;
    private ?MetricRepositoryInterface $metrics = null;
    private ?NamespaceTree $namespaceTree = null;
    private ?ReportCoverage $coverage = null;

    /**
     * Creates a new builder instance.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Adds a single finding.
     */
    public function addFinding(Finding $finding): self
    {
        $this->findings[] = $finding;

        return $this;
    }

    /**
     * Adds multiple findings.
     *
     * @param iterable<Finding> $findings
     */
    public function addFindings(iterable $findings): self
    {
        foreach ($findings as $finding) {
            $this->findings[] = $finding;
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

    public function coverage(ReportCoverage $coverage): self
    {
        $this->coverage = $coverage;

        return $this;
    }

    /**
     * Builds the Report instance.
     */
    public function build(): Report
    {
        $errorCount = 0;
        $warningCount = 0;
        $infoCount = 0;

        foreach ($this->findings as $finding) {
            match ($finding->severity) {
                Severity::Error => $errorCount++,
                Severity::Warning => $warningCount++,
                Severity::Info => $infoCount++,
            };
        }

        return new Report(
            findings: $this->findings,
            filesAnalyzed: $this->filesAnalyzed,
            filesSkipped: $this->filesSkipped,
            duration: $this->duration,
            errorCount: $errorCount,
            warningCount: $warningCount,
            metrics: $this->metrics,
            namespaceTree: $this->namespaceTree,
            infoCount: $infoCount,
            coverage: $this->coverage,
        );
    }
}
