<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;

/**
 * The question `UnmatchedExcludeRule` names but cannot ask, and the findings
 * that answer it.
 *
 * Split from the rule because the answer is only knowable where rules do not
 * run: beside file discovery, while the exclude patterns are still being
 * applied. It does not name the rule class in return — the channel name lives
 * on {@see UnmatchedExcludeOptions}, so the two classes do not form a cycle.
 *
 * **Its Options service is the rule's own, and this service is lazy for that
 * reason.** The container builds the pipeline before the console has applied
 * `rules.<name>.enabled` or `--rule-opt`, so an eagerly constructed audit
 * would capture an Options object that still reads `enabled: true` after the
 * configuration said otherwise — measured, not assumed. Declared lazy, the
 * audit is constructed at its first call, which is inside discovery, after
 * the runtime configuration is settled. `InlineDirectiveValidator` answers to
 * its producer's Options through the same pairing.
 *
 * **Two gates keep this quiet where it cannot judge.** The project-wide one
 * is {@see RunConfiguration::$coversProjectScope}: "this pattern matched
 * nothing" is a fact about the pair (configuration, run scope), and
 * `--exclude=subtree:Legacy` binds nothing when the run was pointed at `src/Domain/`
 * — a path the author of the configuration did not choose. The second is
 * about the pattern itself: before a pattern is reported, the same probe is
 * asked whether it would have removed a directory anywhere in the project
 * tree. `exclude: [{exact: tests}]` written for `qmx check .` binds nothing under
 * `qmx check src/`, and accusing it there reports the caller's choice as the
 * author's mistake. Only a pattern that removes nothing *in the project* is
 * stale, and that is the one this channel names.
 *
 * The second probe is paid for only by a run that would otherwise have
 * reported: it walks at all only when the first walk left a pattern unsettled.
 *
 * **What it cannot judge, it says rather than swallows.** A directory this
 * process may not list hides whatever it holds, so a pattern that could have
 * matched inside it is not called stale. That verdict used to leave the run
 * with the same output as a pattern that bound — no finding, no message,
 * nothing — and for the second walk, which covers tree the run never
 * discovered, there was no other channel it could surface on either. So the
 * unjudged pattern gets a finding of its own, naming the directory that
 * stopped the walk.
 */
final readonly class UnmatchedExcludeAudit
{
    public function __construct(
        private RuleOptionsInterface $options,
        private ExcludeBindingProbe $probe,
    ) {}

    /**
     * One finding per authored pattern this run could not confirm: the ones
     * the project holds no directory for, and the ones no walk could settle.
     *
     * Every project-wide gate is asked before the walk, so a run that cannot
     * report pays nothing for the measurement: the rule switched off, no
     * authored pattern, or a run narrowed below the project's autoload roots.
     *
     * @return list<Finding>
     */
    public function findings(RunConfiguration $configuration): array
    {
        if (!$this->options->isEnabled()
            || $configuration->authoredPathExcludes === []
            || !$configuration->coversProjectScope
        ) {
            return [];
        }

        $verdict = $this->probe->judgeProject($configuration);
        $findings = array_map(UnmatchedExcludeFinding::forPattern(...), $verdict->unbound);
        foreach ($verdict->unlistable as $display => $directory) {
            $findings[] = UnjudgedExcludeFinding::forPattern($display, $directory, $configuration->projectRoot);
        }

        return $findings;
    }
}
