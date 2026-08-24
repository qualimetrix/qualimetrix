<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;

/**
 * The run state of this capability's own subject: the inline directives an
 * analysed run actually carried, and what became of them.
 *
 * Run prepares it once per run from the collection phase's controls, exactly
 * as it prepares the layer policy from the run's graph, and asks it once more
 * after rule execution for the half of the answer that only the produced
 * findings can give.
 *
 * **Why the names live on a contract.** Every channel below is emitted under
 * a `ruleName` of its own, none of which is any rule class's `NAME` — the
 * same arrangement the layer policy uses for its diagnostics. A
 * cross-owner consumer (the composition root, a fixture, a report) must be
 * able to name one without importing the rule, so the literals live here and
 * the rule refers to them.
 *
 * The channels answer the three states an authored directive can be in:
 *
 * - it does not address a unit this directive can address — a configuration
 *   error, reported on {@see UNRESOLVED_DIRECTIVE_NAME} or, when the name
 *   does resolve to a rule that simply cannot be retuned, on
 *   {@see UNSUPPORTED_THRESHOLD_NAME}. Malformed values are the same kind of
 *   mistake and keep {@see INVALID_THRESHOLD_NAME};
 * - it is well formed but did nothing this run — ordinary debt cleanup,
 *   reported on {@see UNUSED_DIRECTIVE_NAME};
 * - it is well formed and did something — silence.
 */
interface InlineDirectivePolicyInterface
{
    /**
     * The rule that owns all four channels. It is a producer name, not a
     * channel: nothing is ever emitted under it.
     */
    public const string PRODUCER_RULE_NAME = 'annotation.directive';

    /** A directive naming something this directive cannot address. */
    public const string UNRESOLVED_DIRECTIVE_NAME = 'annotation.unresolved-directive';

    /** A threshold directive naming a rule that declares no override support. */
    public const string UNSUPPORTED_THRESHOLD_NAME = 'annotation.unsupported-threshold';

    /** A threshold directive whose values do not parse or do not validate. */
    public const string INVALID_THRESHOLD_NAME = 'annotation.invalid-threshold';

    /** A well-formed suppression that suppressed nothing this run. */
    public const string UNUSED_DIRECTIVE_NAME = 'annotation.unused-directive';

    /**
     * @param array<string, list<Suppression>> $suppressions file => directives
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides file => directives
     * @param array<string, list<ThresholdDiagnostic>> $thresholdDiagnostics file => diagnostics
     */
    public function prepare(array $suppressions, array $thresholdOverrides, array $thresholdDiagnostics): void;

    /** Clears prepared run state, including the reporting gate. */
    public function reset(): void;

    /**
     * The findings only the produced set can justify: suppressions that
     * addressed something real and still matched nothing.
     *
     * Answers an empty list unless the owning rule ran and was enabled — the
     * rule is the single switch, so `--disable-rule` and `enabled: false`
     * both silence this without a second one.
     *
     * @param list<Finding> $findings everything the rules produced this run
     *
     * @return list<Finding>
     */
    public function auditDirectiveUsage(array $findings): array;
}
