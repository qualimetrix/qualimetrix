<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

final readonly class AnalysisResult
{
    /** Canonical source of truth for discovered-file terminal states. */
    public AnalysisCoverage $coverage;

    /** Compatibility accessor derived from {@see $coverage}. */
    public int $filesAnalyzed;

    /** Compatibility accessor derived from {@see $coverage}; includes intentional generated exclusions. */
    public int $filesSkipped;

    /**
     * @param list<Violation> $violations
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file `@qmx-threshold`
     *                                                                   overrides — the same map
     *                                                                   {@see \Qualimetrix\Core\Rule\AnalysisContext}
     *                                                                   used to evaluate rules, kept
     *                                                                   here too so a caller outside
     *                                                                   rule execution (e.g.
     *                                                                   `baseline:explain`) can read
     *                                                                   the annotation a symbol carried
     *                                                                   in *this* run
     */
    public function __construct(
        public array $violations,
        public float $duration,
        public MetricRepositoryInterface $metrics,
        AnalysisCoverage $coverage,
        public array $suppressions = [],
        public ?NamespaceTree $namespaceTree = null,
        public array $thresholdOverrides = [],
    ) {
        $this->coverage = $coverage;
        $this->filesAnalyzed = $this->coverage->analyzedFilesCount();
        $this->filesSkipped = $this->coverage->skippedFilesCount();
    }

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

    public function hasInfo(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity === Severity::Info) {
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
        $mergedMetrics = $this->metrics->mergedWith($other->metrics) ?? $this->metrics;

        $mergedSuppressions = $this->suppressions;
        foreach ($other->suppressions as $file => $list) {
            $mergedSuppressions[$file] = array_merge($mergedSuppressions[$file] ?? [], $list);
        }

        $mergedThresholdOverrides = $this->thresholdOverrides;
        foreach ($other->thresholdOverrides as $file => $list) {
            $mergedThresholdOverrides[$file] = array_merge($mergedThresholdOverrides[$file] ?? [], $list);
        }

        return new self(
            violations: [...$this->violations, ...$other->violations],
            duration: max($this->duration, $other->duration),
            metrics: $mergedMetrics,
            coverage: $this->coverage->merge($other->coverage),
            suppressions: $mergedSuppressions,
            namespaceTree: $this->namespaceTree ?? $other->namespaceTree,
            thresholdOverrides: $mergedThresholdOverrides,
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
            $fileCompare = strcmp($a->location->pathString(), $b->location->pathString());
            if ($fileCompare !== 0) {
                return $fileCompare;
            }

            return ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
        });

        return $sorted;
    }

    /**
     * @return array{errors: int, warnings: int, info: int}
     */
    public function getViolationCountBySeverity(): array
    {
        $errors = 0;
        $warnings = 0;
        $info = 0;

        foreach ($this->violations as $violation) {
            match ($violation->severity) {
                Severity::Error => $errors++,
                Severity::Warning => $warnings++,
                Severity::Info => $info++,
            };
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
        ];
    }
}
