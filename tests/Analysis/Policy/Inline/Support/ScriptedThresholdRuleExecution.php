<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Support;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * One rule, honestly obeying `@qmx-threshold`, and counting how often it ran.
 *
 * The audit's whole method is to execute rules again with a directive taken
 * out, so a stub returning a canned list would prove nothing: what has to be
 * modelled is a rule that reads the override off the context and reports
 * accordingly. This one does exactly that and nothing else, which also makes
 * the execution count meaningful — a sweep that removes bindings one at a time
 * instead of a directive at a time runs a different number of times.
 */
final class ScriptedThresholdRuleExecution implements RuleExecutionInterface
{
    public int $executions = 0;

    /**
     * @param list<array{subject: MetricSubject, value: int|float}> $measurements
     * @param bool $publishesBoundary whether findings carry the boundary they were judged
     *                                against, as nine of the twenty-seven rule files do not
     * @param bool $excludedFromPublished whether the per-rule ledger drops every finding from
     *                                    the published half, leaving it only in the produced one
     * @param ?int $driftsAtExecution the execution number that invents an extra finding, standing
     *                                in for a rule carrying state across runs
     * @param ?AnalysisContext $answersOnlyFor a context this rule treats as the one true world: given
     *                                         any other object, even an equal one, it reports one more
     *                                         finding. Stands in for a rule that distinguishes the run's
     *                                         own context from a rebuilt one, which is the drift a
     *                                         control executing against the original object cannot see
     * @param bool $driftsWhenRestricted whether this rule reports one extra finding whenever it runs
     *                                   alone (`$restrictToProducer !== null`). Stands in for a rule
     *                                   whose output depends on a neighbour having run beside it — the
     *                                   drift a narrowed reference pass, not a naively-full one, exists
     *                                   to catch
     * @param bool $switchedOffByItsOptions whether the run recorded this producer as having run at none
     *                                      of its levels. Scripted rather than derived, because the
     *                                      double has no options object to ask — what the audit reads
     *                                      is the record, and this is that record. The record it
     *                                      writes names one level, `callable`, because every subject
     *                                      this double measures is a method; a double reporting at
     *                                      more levels than it measures would let a test pass on a
     *                                      pair the product never produces
     */
    public function __construct(
        private readonly string $rule,
        private readonly array $measurements,
        private readonly int|float $warning,
        private readonly int|float $error,
        private readonly RelativePath $file,
        private readonly bool $publishesBoundary = true,
        private readonly bool $excludedFromPublished = false,
        private readonly ?int $driftsAtExecution = null,
        private readonly ?AnalysisContext $answersOnlyFor = null,
        private readonly bool $driftsWhenRestricted = false,
        private readonly bool $switchedOffByItsOptions = false,
    ) {}

    public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult
    {
        // Honoured rather than ignored, and the count is not incremented when
        // the narrowing excludes this rule: a double that ran anyway would make
        // a narrowed sweep and a full one indistinguishable here, which is the
        // one difference the tests using it are about.
        if ($restrictToProducer !== null && $restrictToProducer !== $this->rule) {
            return new RuleExecutionResult([], [], new RuleExclusionStats(), $this->levelActivity());
        }

        ++$this->executions;

        $produced = [];

        foreach ($this->measurements as $measurement) {
            $override = $context->getThresholdOverride($this->rule, $measurement['subject']);
            $warning = $override->warning ?? $this->warning;
            $error = $override->error ?? $this->error;

            $severity = match (true) {
                $measurement['value'] >= $error => Severity::Error,
                $measurement['value'] >= $warning => Severity::Warning,
                default => null,
            };

            if ($severity === null) {
                continue;
            }

            $produced[] = $this->finding(
                $measurement['subject'],
                $measurement['value'],
                $severity === Severity::Error ? $error : $warning,
                $severity,
            );
        }

        if ($this->answersOnlyFor !== null && $context !== $this->answersOnlyFor) {
            $produced[] = $this->finding($this->measurements[0]['subject'], 2, 2, Severity::Warning);
        }

        if ($this->driftsAtExecution === $this->executions) {
            $produced[] = $this->finding($this->measurements[0]['subject'], 1, 1, Severity::Warning);
        }

        if ($this->driftsWhenRestricted && $restrictToProducer !== null) {
            $produced[] = $this->finding($this->measurements[0]['subject'], 3, 3, Severity::Warning);
        }

        return new RuleExecutionResult(
            $produced,
            $this->excludedFromPublished ? [] : $produced,
            new RuleExclusionStats(),
            levelActivity: $this->levelActivity(),
        );
    }

    /**
     * A scripted executor answers about the rules it was scripted with, and
     * nothing here is switched off by configuration: an empty snapshot
     * declares no pair, which the audit reads as "not a disablement" rather
     * than as "disabled".
     */
    public function levelActivity(): LevelActivity
    {
        return LevelActivity::fromMap(
            [$this->rule => [SymbolLevel::Callable->value => !$this->switchedOffByItsOptions]],
        );
    }

    public function allRules(): array
    {
        return [];
    }

    private function finding(
        MetricSubject $subject,
        int|float $value,
        int|float $boundary,
        Severity $severity,
    ): Finding {
        return new Finding(
            location: new Location($this->file, 1),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->rule,
            code: $this->rule,
            message: $this->publishesBoundary
                ? \sprintf('%s: %s (threshold: %s)', $this->rule, $value, $boundary)
                : \sprintf('%s: %s', $this->rule, $value),
            severity: $severity,
            metricValue: $value,
            threshold: $this->publishesBoundary ? $boundary : null,
        );
    }
}
