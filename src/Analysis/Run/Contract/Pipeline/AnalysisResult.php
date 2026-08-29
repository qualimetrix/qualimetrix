<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;

final readonly class AnalysisResult
{
    /** Canonical source of truth for discovered-file terminal states. */
    public AnalysisCoverage $coverage;

    /** Compatibility accessor derived from {@see $coverage}. */
    public int $filesAnalyzed;

    /** Compatibility accessor derived from {@see $coverage}; includes intentional generated exclusions. */
    public int $filesSkipped;

    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file `@qmx-threshold`
     *                                                                   overrides — the same map
     *                                                                   {@see \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext}
     *                                                                   used to evaluate rules, kept
     *                                                                   here too so a caller outside
     *                                                                   rule execution (e.g.
     *                                                                   `baseline:explain`) can read
     *                                                                   the annotation a symbol carried
     *                                                                   in *this* run
     * @param ?RuleExecutionResult $ruleExecution What this run's rule execution produced before the per-rule
     *                                            exclusion ledger and channel selection ran, what it published
     *                                            after, and the exclusion tally — the single source a consumer
     *                                            outside rule execution (e.g. `FindingFilterOrchestrator`, or a
     *                                            future audit comparing produced against published) reads
     *                                            instead of a second, separately mutable accessor. `null` only
     *                                            for values built outside a real pipeline run.
     *
     * @qmx-threshold code-smell.constructor-overinjection warning=9 error=9 -- Transport VO for one pipeline
     *                run, carrying three subjects with no value of their own yet: what the run measured
     *                (metrics, coverage, namespaceTree, duration), what controls were in force going in
     *                (suppressions, thresholdOverrides), and what rules said coming out (findings,
     *                ruleExecution) — the last pair already overlaps, since `findings` is exactly
     *                `ruleExecution`'s published half plus the directive-usage audit. The eight-parameter
     *                count is the cost of that unsplit shape, not eight independent facts; splitting by
     *                subject is the real fix and is out of scope here because it moves every consumer that
     *                reaches this VO, not only this constructor.
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=9 -- Same VO, same unsplit shape; see the
     *                constructor-overinjection annotation above.
     */
    public function __construct(
        public array $findings,
        public float $duration,
        public MetricRepositoryInterface $metrics,
        AnalysisCoverage $coverage,
        public array $suppressions = [],
        public ?NamespaceTree $namespaceTree = null,
        public array $thresholdOverrides = [],
        public ?RuleExecutionResult $ruleExecution = null,
    ) {
        $this->coverage = $coverage;
        $this->filesAnalyzed = $this->coverage->analyzedFilesCount();
        $this->filesSkipped = $this->coverage->skippedFilesCount();
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === Severity::Warning) {
                return true;
            }
        }

        return false;
    }

    public function hasInfo(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === Severity::Info) {
                return true;
            }
        }

        return false;
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

        // Neither side's rule execution is dropped when both are present: a
        // silent "take the first" would leave $findings as the union of both
        // runs while $ruleExecution answered for only one of them.
        $mergedRuleExecution = match (true) {
            $this->ruleExecution === null => $other->ruleExecution,
            $other->ruleExecution === null => $this->ruleExecution,
            default => $this->ruleExecution->merge($other->ruleExecution),
        };

        return new self(
            findings: [...$this->findings, ...$other->findings],
            duration: max($this->duration, $other->duration),
            metrics: $mergedMetrics,
            coverage: $this->coverage->merge($other->coverage),
            suppressions: $mergedSuppressions,
            namespaceTree: $this->namespaceTree ?? $other->namespaceTree,
            thresholdOverrides: $mergedThresholdOverrides,
            ruleExecution: $mergedRuleExecution,
        );
    }

    /**
     * Returns findings sorted by file and line.
     *
     * @return list<Finding>
     */
    public function getSortedFindings(): array
    {
        $sorted = $this->findings;

        usort($sorted, static function (Finding $a, Finding $b): int {
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

        foreach ($this->findings as $finding) {
            match ($finding->severity) {
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
