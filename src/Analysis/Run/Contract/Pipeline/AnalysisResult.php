<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;

use Qualimetrix\Analysis\Finding\Contract\Finding;

use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;

final readonly class AnalysisResult
{
    /**
     * @param list<Finding> $latePublished
     * @param list<array{publishedCount: int<0, max>, lateCount: int<0, max>}> $publicationOrder
     *
     * @qmx-threshold code-smell.constructor-overinjection warning=9 error=9 -- Eight independent immutable observations describe one ordered run result. Splitting them would relocate the same transport record; the exclusive boundary keeps the next field visible.
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=9 -- Eight independent immutable observations describe one ordered run result. Splitting them would relocate the same transport record; the exclusive boundary keeps the next field visible.
     */
    private function __construct(
        public MeasuredRunResult $measured,
        public DirectiveObservations $directives,
        public ?RuleExecutionResult $ruleExecution,
        public array $latePublished,
        private array $publicationOrder,
        public ComputedMetricEvaluationSummary $computedMetricEvaluation,
        public JudgedPopulation $population,
        public ?ChannelPublication $populationPublication,
    ) {}

    /**
     * @param RuleExecutionResult|null $ruleExecution Null only for values built outside a real pipeline run
     * @param list<Finding> $latePublished Findings assembled after rule execution, without its published findings
     */
    public static function fromRun(
        MeasuredRunResult $measured,
        DirectiveObservations $directives,
        ?RuleExecutionResult $ruleExecution,
        array $latePublished,
        ComputedMetricEvaluationSummary $computedMetricEvaluation = new ComputedMetricEvaluationSummary(),
        ?JudgedPopulation $population = null,
        ?ChannelPublication $populationPublication = null,
    ): self {
        return new self($measured, $directives, $ruleExecution, $latePublished, [[
            'publishedCount' => \count($ruleExecution->published ?? []),
            'lateCount' => \count($latePublished),
        ]], $computedMetricEvaluation, ($population ?? JudgedPopulation::empty())->merge($ruleExecution->population ?? JudgedPopulation::empty()), $populationPublication);
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        $published = $this->ruleExecution->published ?? [];
        $findings = [];
        $publishedOffset = 0;
        $lateOffset = 0;

        // Grouping the two collections would move a left run's late findings
        // after the right run's published findings during a merge.
        foreach ($this->publicationOrder as $segment) {
            array_push($findings, ...\array_slice($published, $publishedOffset, $segment['publishedCount']));
            array_push($findings, ...\array_slice($this->latePublished, $lateOffset, $segment['lateCount']));
            $publishedOffset += $segment['publishedCount'];
            $lateOffset += $segment['lateCount'];
        }

        return $findings;
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings() as $finding) {
            if ($finding->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->findings() as $finding) {
            if ($finding->severity === Severity::Warning) {
                return true;
            }
        }

        return false;
    }

    public function hasInfo(): bool
    {
        foreach ($this->findings() as $finding) {
            if ($finding->severity === Severity::Info) {
                return true;
            }
        }

        return false;
    }

    public function merge(self $other): self
    {
        return new self(
            measured: $this->measured->merge($other->measured),
            directives: $this->directives->merge($other->directives),
            ruleExecution: $this->mergedRuleExecution($other),
            latePublished: [...$this->latePublished, ...$other->latePublished],
            publicationOrder: [...$this->publicationOrder, ...$other->publicationOrder],
            computedMetricEvaluation: $this->computedMetricEvaluation->merge($other->computedMetricEvaluation),
            population: $this->population->merge($other->population),
            populationPublication: $this->populationPublication === $other->populationPublication ? $this->populationPublication : null,
        );
    }

    private function mergedRuleExecution(self $other): ?RuleExecutionResult
    {
        return match (true) {
            $this->ruleExecution === null => $other->ruleExecution,
            $other->ruleExecution === null => $this->ruleExecution,
            default => $this->ruleExecution->merge($other->ruleExecution),
        };

    }

    /**
     * Returns findings sorted by file and line.
     *
     * @return list<Finding>
     */
    public function getSortedFindings(): array
    {
        $sorted = $this->findings();

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

        foreach ($this->findings() as $finding) {
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
