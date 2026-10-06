<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Directive\RefusedDirectives;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\RelativePath;

/**
 * The post-execution half of the inline-directive subject: what each authored
 * suppression did this run.
 *
 * The answer is a {@see DirectiveVerdict} per authored site, and the stale
 * findings {@see stale()} returns are one projection of it. Two computations
 * would be two chances to disagree about one directive, which is why the
 * projection reads the verdicts rather than repeating the accounting.
 *
 * **One computation, one universe.** `annotation.unused-directive` is this
 * class's own output, and {@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveChannelBan} is why no accounting
 * has to be done about it: a directive that reaches the channel is refused
 * where it was written, and one that reaches it without naming it — the form
 * with no rule filter — silences nothing, because the publication filter
 * declines to apply any directive to that channel. Two devices used to hold
 * that line and are gone with the loophole they patched: the verdicts were
 * judged against a wider list than the findings were, and a directive had to
 * be denied credit for the complaint it earned by being dead. Both existed
 * only to correct the crediting of this one channel, and nothing is credited
 * with it any more.
 *
 * It is a pure function of the prepared directives and the produced findings,
 * and it holds no run state — {@see \Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy} keeps that and asks
 * this once, after every rule has finished. Splitting the two is what keeps
 * the run state a store: the accounting needs the channel universe, the rule
 * selection and the finding vocabulary, and none of those has anything to do
 * with holding directives for the length of a run.
 *
 * `verdicts()` is public with no production caller yet: the operation that
 * carries verdicts across the owner boundary lands with its consumer. That is a
 * method on an internal class, invisible to the manifest — unlike a contract
 * operation, which the checker refuses without a consumer that exists.
 */
final class DirectiveUsage
{
    private readonly DirectiveMeasurability $measurability;

    /**
     * Two views and not the composite that implements both: the composite
     * exists so one object can answer everything, not so every consumer may
     * ask everything, and it says so itself. The composition root passes the
     * same instance to both, which is what makes the two answers one universe.
     */
    public function __construct(
        ChannelIdentityInterface $identity,
        RuleConfigurationInterface $ruleConfiguration,
        private readonly ChannelDeclarationRegistryInterface $declarations,
        private readonly RefusedDirectives $refused,
    ) {
        $this->measurability = new DirectiveMeasurability($identity, $ruleConfiguration, $declarations);
    }

    /**
     * What each authored suppression did this run.
     *
     * The single computation behind both answers this class gives: `stale()`
     * is its projection into findings, and a report that lists directives
     * reads it directly. Two computations would be two chances to disagree
     * about the same directive.
     *
     * @param array<string, list<Suppression>> $suppressionsByFile file => directives, as prepared
     * @param list<Finding> $findings everything the rules produced this run
     *
     * @return list<DirectiveVerdict>
     */
    public function verdicts(array $suppressionsByFile, array $findings, LevelActivity $activity, SubjectCoverageFacts $subjectCoverage): array
    {
        return array_map(
            static fn(array $pair): DirectiveVerdict => $pair['verdict'],
            $this->evaluate($suppressionsByFile, $findings, $activity, $subjectCoverage),
        );
    }

    /**
     * The suppressions that addressed something real and still matched
     * nothing this run.
     *
     * @param array<string, list<Suppression>> $suppressionsByFile file => directives, as prepared
     * @param list<Finding> $findings everything the rules produced this run
     *
     * @return list<Finding>
     */
    public function stale(
        array $suppressionsByFile,
        array $findings,
        Severity $severity,
        LevelActivity $activity,
        SubjectCoverageFacts $subjectCoverage,
    ): array {
        $stale = [];

        foreach ($this->evaluate($suppressionsByFile, $findings, $activity, $subjectCoverage) as $pair) {
            if ($pair['verdict']->effect === DirectiveEffect::Inert) {
                $stale[] = StaleDirectiveFinding::of($pair['verdict']->site->file, $pair['directive'], $severity);
            }
        }

        return $stale;
    }

    /**
     * The findings a suppression could have silenced at all.
     *
     * A channel a configuration validator declares is exempt from annotation
     * suppression by the kind of thing it is, not by anyone's configuration:
     * a misconfigured directive is not silenced by another directive, and the
     * projection enforces that for every report. Counting such a finding as
     * something a suppression matched would call a directive live that can
     * never do anything — measured on a fixture, `@qmx-ignore-file annotation.unresolved-directive` reported "effective" while `check`
     * printed the error it claimed to silence.
     *
     * This is not the publication ledger of D4 creeping back in. That ledger
     * is a configuration choice about a report; this is a property of the
     * producing type, true for every run and every configuration.
     *
     * **The banned channel is deliberately not filtered here, and the omission
     * is a decision.** {@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveChannelBan} makes every directive
     * that could reach it unmeasurable, and {@see evaluate()} short-circuits on
     * a reason before it ever asks what a directive silenced — so a branch
     * dropping that channel here could not be reached by any authored form,
     * and nothing could prove it right or wrong. The symmetry a later reader
     * will be tempted to complete is untestable, not missing. A configuration
     * error is the opposite case: those directives are accepted, and this
     * filter is the only thing that keeps them from being called live.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function suppressible(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn(Finding $finding): bool
                => $this->declarations->declarationFor($finding->channel())?->isConfigurationError() !== true,
        ));
    }

    /**
     * One verdict per authored site, paired with the directive it came from.
     *
     * The pair exists because the two projections need different halves: the
     * report reads the verdict, and the finding needs the directive to render
     * the target the author wrote.
     *
     * @param array<string, list<Suppression>> $suppressionsByFile
     * @param list<Finding> $findings
     *
     * @return list<array{verdict: DirectiveVerdict, directive: Suppression}>
     */
    private function evaluate(array $suppressionsByFile, array $findings, LevelActivity $activity, SubjectCoverageFacts $subjectCoverage): array
    {
        $findings = $this->suppressible($findings);
        $evaluated = [];

        foreach ($suppressionsByFile as $file => $fileSuppressions) {
            foreach (self::groupByAuthoredSite($fileSuppressions) as $group) {
                $directive = $group[0];
                if ($this->refused->suppression(RelativePath::fromString($file), $directive) !== null) {
                    continue;
                }
                $fired = self::anyOfTheGroupFired($file, $group, $findings);
                $reason = $fired ? null : $this->measurability->unmeasurableReason($file, $group, $activity, $subjectCoverage);

                $effect = match (true) {
                    $reason !== null => DirectiveEffect::Unmeasured,
                    $fired => DirectiveEffect::Effective,
                    default => DirectiveEffect::Inert,
                };

                $evaluated[] = [
                    'verdict' => new DirectiveVerdict(
                        site: new DirectiveSite(
                            file: RelativePath::fromString($file),
                            line: $directive->line,
                            form: $directive->form(),
                            target: (string) $directive->target(),
                            position: $directive->position,
                        ),
                        effect: $effect,
                        reason: $reason,
                    ),
                    'directive' => $directive,
                ];
            }
        }

        return $evaluated;
    }

    /**
     * The same identity as {@see \Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy::authoredSuppressions()},
     * but keeping every binding rather than one.
     *
     * The usage question genuinely needs them all: the author wrote one
     * directive, and it did something as soon as *any* declaration it covers
     * was silenced — which for a class-level directive is usually the class
     * and not the five methods beside it.
     *
     * @param list<Suppression> $suppressions
     *
     * @return list<non-empty-list<Suppression>>
     */
    private static function groupByAuthoredSite(array $suppressions): array
    {
        $groups = [];

        foreach ($suppressions as $suppression) {
            $groups[$suppression->authoredSite()][] = $suppression;
        }

        return array_values($groups);
    }

    /**
     * The group fired if any of its bindings did: the author wrote one
     * directive, and it did something as soon as one subject it covers was
     * silenced.
     *
     * @param list<Suppression> $group
     * @param list<Finding> $findings
     */
    private static function anyOfTheGroupFired(string $file, array $group, array $findings): bool
    {
        foreach ($group as $suppression) {
            if (SuppressionFilter::suppressesAny($file, $suppression, $findings)) {
                return true;
            }
        }

        return false;
    }
}
