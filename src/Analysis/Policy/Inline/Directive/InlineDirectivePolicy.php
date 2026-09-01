<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;

/**
 * Per-run state for the inline-directive subject.
 *
 * Mirrors the layer policy: Run prepares it, the owning rule reads it, and
 * nothing outside this capability touches the directives themselves.
 *
 * The usage half is separate from the validation half for a reason that is
 * about time, not taste. Whether a name addresses anything is knowable the
 * moment configuration has resolved, so the rule answers it while it runs.
 * Whether a suppression suppressed anything is knowable only once every rule
 * has produced its findings, which is after every rule — including this one —
 * has finished. So Run asks twice, and the second answer is assembled inside
 * this capability rather than at the call site, so the channel identity stays
 * with its owner.
 *
 * Assembling it is {@see DirectiveUsage}'s job, not this class's: holding the
 * directives for the length of a run needs three collaborators, while turning
 * them into findings needs the channel universe, the rule selection and the
 * finding vocabulary on top. This class keeps the state and forwards the
 * question.
 */
final class InlineDirectivePolicy implements InlineDirectivePolicyInterface
{
    /** @var array<string, list<Suppression>> */
    private array $suppressions = [];

    /** @var array<string, list<ThresholdOverride>> */
    private array $thresholdOverrides = [];

    /** @var array<string, list<ThresholdDiagnostic>> */
    private array $thresholdDiagnostics = [];

    /**
     * Off until the owning rule turns it on, which it does only when it
     * actually runs and is enabled. One switch, not two.
     */
    private ?Severity $usageReportingSeverity = null;

    public function __construct(private readonly DirectiveUsage $usage) {}

    public function prepare(array $suppressions, array $thresholdOverrides, array $thresholdDiagnostics): void
    {
        $this->suppressions = $suppressions;
        $this->thresholdOverrides = $thresholdOverrides;
        $this->thresholdDiagnostics = $thresholdDiagnostics;
        $this->usageReportingSeverity = null;
    }

    /**
     * The directives **as authored**, one entry per line of source, keyed by
     * file.
     *
     * This is the only view the reporting side is ever given, and the reason
     * is the extraction shape: a directive written on a class docblock is
     * materialised once per declaration that docblock governs — the class and
     * every method in it. Those are bindings of one annotation, not several
     * annotations, so a report that lists them separately says "you made
     * forty-one mistakes" about a single typo. Since a configuration error
     * ends the run past `fail_on`, that is precisely the report a reader
     * learns to skip.
     *
     * @return array<string, list<Suppression>>
     */
    public function authoredSuppressions(): array
    {
        return self::onePerAuthoredSite(
            $this->suppressions,
            static fn(Suppression $s): string => $s->line . "\0" . $s->type->value . "\0" . $s->rule,
        );
    }

    /** @return array<string, list<ThresholdOverride>> */
    public function authoredThresholdOverrides(): array
    {
        return self::onePerAuthoredSite(
            $this->thresholdOverrides,
            static fn(ThresholdOverride $o): string => $o->line . "\0" . $o->rulePattern,
        );
    }

    /** @return array<string, list<ThresholdDiagnostic>> */
    public function authoredThresholdDiagnostics(): array
    {
        return self::onePerAuthoredSite(
            $this->thresholdDiagnostics,
            static fn(ThresholdDiagnostic $d): string => $d->line . "\0" . ($d->code ?? '') . "\0" . $d->message,
        );
    }

    /**
     * Keeps the first binding of each authored site and drops the rest.
     *
     * Which binding survives does not matter: the reporting side never reads
     * a binding's subject, only the file and line the annotation was written
     * at. It is the identity key — line, form, and authored text — that
     * decides what counts as one directive.
     *
     * @template T of object
     *
     * @param array<string, list<T>> $byFile
     * @param callable(T): string $identity
     *
     * @return array<string, list<T>>
     */
    private static function onePerAuthoredSite(array $byFile, callable $identity): array
    {
        $authored = [];

        foreach ($byFile as $file => $items) {
            $seen = [];
            foreach ($items as $item) {
                $key = $identity($item);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $authored[$file][] = $item;
            }
        }

        return $authored;
    }

    /**
     * Called by the owning rule as it runs, which is what makes the rule's
     * own enablement the single gate on the post-execution half.
     */
    public function enableUsageReporting(Severity $severity): void
    {
        $this->usageReportingSeverity = $severity;
    }

    public function directiveVerdicts(array $producedFindings): array
    {
        return $this->usage->verdicts($this->suppressions, $producedFindings);
    }

    public function auditDirectiveUsage(array $findings): array
    {
        $severity = $this->usageReportingSeverity;
        if ($severity === null) {
            return [];
        }

        return $this->usage->stale($this->suppressions, $findings, $severity);
    }
}
