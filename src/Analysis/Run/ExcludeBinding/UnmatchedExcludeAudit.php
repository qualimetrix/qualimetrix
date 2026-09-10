<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

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
 * `--exclude=Legacy` binds nothing when the run was pointed at `src/Domain/`
 * — a path the author of the configuration did not choose. The second is
 * about the pattern itself: before a pattern is reported, the same probe is
 * asked whether it would have removed a directory anywhere in the project
 * tree. `exclude: [tests]` written for `qmx check .` binds nothing under
 * `qmx check src/`, and accusing it there reports the caller's choice as the
 * author's mistake. Only a pattern that removes nothing *in the project* is
 * stale, and that is the one this channel names.
 *
 * The second probe is paid for only by a run that would otherwise have
 * reported: it walks at all only when the first walk left a pattern unbound.
 */
final readonly class UnmatchedExcludeAudit
{
    /**
     * What one finding here is about: the pattern.
     *
     * Without it every finding on this channel shared one baseline identity —
     * project subject, one channel, no occurrence — and the entry bounded
     * their *number*. Accepting two stale patterns then accepted any two,
     * including one introduced by the next edit. A value that points at
     * nothing is the whole content of the finding, so it is the whole content
     * of the identity too.
     */
    private const string OCCURRENCE_KIND = 'unmatched-exclude-pattern';

    public function __construct(
        private RuleOptionsInterface $options,
        private ExcludeBindingProbe $probe,
    ) {}

    /**
     * One finding per authored pattern this run's paths hold no directory for.
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

        $unboundInRun = $this->probe->unboundPatterns(
            $configuration->paths,
            $configuration->authoredPathExcludes,
            $configuration->pathExcludes,
        );

        if ($unboundInRun === []) {
            return [];
        }

        // The same question against the whole tree: a pattern that binds
        // somewhere the run did not look names code that exists, and this run
        // cannot tell that from a pattern whose directory is gone.
        return array_map(self::finding(...), $this->probe->unboundPatterns(
            [$configuration->projectRoot],
            $unboundInRun,
            $configuration->pathExcludes,
        ));
    }

    private static function finding(string $pattern): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: UnmatchedExcludeOptions::CHANNEL,
            code: UnmatchedExcludeOptions::CHANNEL,
            message: \sprintf(
                'The exclude pattern "%s" matched no directory anywhere in the project, so nothing was left out'
                . ' for it. Every file it was written to skip was measured, and this report covers them.',
                $pattern,
            ),
            severity: Severity::Warning,
            recommendation: \sprintf(
                'Check "%s" against the tree: a pattern without a slash matches a directory name at any depth,'
                . ' and one with a slash matches a path segment sequence. Drop the entry if the directory is'
                . ' gone.',
                $pattern,
            ),
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, ['pattern' => $pattern]),
        );
    }
}
