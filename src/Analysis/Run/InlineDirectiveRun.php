<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run;

use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Finding;

use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;

/** Coordinates the authored Inline directives across one prepared Run. */
final readonly class InlineDirectiveRun
{
    public function __construct(
        private InlineDirectivePolicyInterface $policy,
        private ThresholdDirectiveAuditInterface $thresholdAudit,
    ) {}

    /**
     * Authored state is prepared even when the Inline reporting rule is disabled.
     *
     * @param array<string, list<Suppression>> $suppressions
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides
     * @param array<string, list<ThresholdDiagnostic>> $thresholdDiagnostics
     */
    public function prepare(
        array $suppressions,
        array $thresholdOverrides,
        array $thresholdDiagnostics,
    ): void {
        $this->policy->prepare($suppressions, $thresholdOverrides, $thresholdDiagnostics);
    }

    /**
     * @param list<Finding> $produced
     *
     * @return array{findings: list<Finding>, population: JudgedPopulation}
     */
    public function usageResult(array $produced, LevelActivity $activity, SubjectCoverageFacts $subjectCoverage, ChannelPublication $publication): array
    {
        return $this->policy->auditDirectiveUsage($produced, $activity, $subjectCoverage, $publication);
    }

    /**
     * Both kinds of verdict form one authored-site ordered dialogue.
     *
     * @param list<Finding> $produced
     *
     * @return list<DirectiveVerdict>
     */
    public function verdicts(
        array $produced,
        LevelActivity $activity,
        SubjectCoverageFacts $subjectCoverage,
        AnalysisContext $context,
        RuleExecutionInterface $executor,
        RuleExecutionResult $baseline,
        DirectiveSweepScope $sweep,
    ): array {
        $verdicts = [
            ...$this->policy->directiveVerdicts($produced, $activity, $subjectCoverage),
            ...$this->thresholdAudit->verdicts(new ThresholdDirectiveAuditInput($context, $executor, $baseline, $sweep)),
        ];

        usort(
            $verdicts,
            static fn(DirectiveVerdict $left, DirectiveVerdict $right): int
                => [$left->site->file->value(), $left->site->line, $left->site->form, $left->site->target]
                <=> [$right->site->file->value(), $right->site->line, $right->site->form, $right->site->target],
        );

        return $verdicts;
    }
}
