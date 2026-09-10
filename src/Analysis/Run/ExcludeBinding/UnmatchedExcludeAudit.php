<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
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
 * **The gate that keeps this quiet on a narrowed run** is
 * {@see RunConfiguration::$coversProjectScope}, the one predicate the round
 * uses. "This pattern matched nothing" is a fact about the pair
 * (configuration, run scope): `--exclude=Legacy` binds nothing when the run
 * was pointed at `src/Domain/`, and the author of the configuration did not
 * choose that path — the caller did. On a narrowed run the honest answer is
 * that there is nothing here to judge, so the channel says nothing. The cost
 * is that a genuinely stale exclusion waits for a whole-project run, which is
 * the only run that can tell the two apart.
 */
final readonly class UnmatchedExcludeAudit
{
    public function __construct(
        private RuleOptionsInterface $options,
        private ExcludeBindingProbe $probe,
    ) {}

    /**
     * One finding per authored pattern this run's paths hold no directory for.
     *
     * Every gate is asked before the walk, so a run that cannot report pays
     * nothing for the measurement: the rule switched off, no authored pattern,
     * or a run narrowed below the project's autoload roots.
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

        return array_map(self::finding(...), $this->probe->unboundPatterns(
            $configuration->paths,
            $configuration->authoredPathExcludes,
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
                'The exclude pattern "%s" matched no directory in the analysed paths, so nothing was left out'
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
        );
    }
}
