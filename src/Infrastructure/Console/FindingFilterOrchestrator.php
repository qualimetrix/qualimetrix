<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeChannels;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\ValueScopeJudgement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Reporting\ReportProjectScope;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turns `check`'s options into a pipeline run and reports what the run's
 * stages did — stale baseline entries, resolved entries, inert entries,
 * a scope mismatch against the loaded baseline, suppressed and excluded
 * findings.
 *
 * This class is where `InputInterface` stops: the pipeline and
 * {@see MeasuredFindingSet} below it take values, which is what lets a
 * command with a different option surface measure the same set.
 */
final readonly class FindingFilterOrchestrator
{
    public function __construct(
        private FindingProjector $findingProjector,
        private ErrorStream $errorStream,
        private UnboundSuppressionAudit $unboundSuppressionAudit,
        private ComposerManifestReaderInterface $composerReader,
        private ObservedProjectScopeReasons $observedProjectScopeReasons,
        private ProjectTreeQueryInterface $projectTree,
        private RunRuleCoverage $ruleCoverage,
    ) {}

    public function projectionOptions(
        ConfiguredFindingExclusions $configuredExclusions,
        InputInterface $input,
        GitScopeResolution $scope,
    ): FindingProjectionOptions {
        $cliExcludePaths = CommandLineSpelling::options($input, 'suppress-path');
        $cliExcludeNamespaces = CommandLineSpelling::options($input, 'suppress-namespace');
        $decoder = new CliSelectorDecoder();
        $exclusions = $configuredExclusions->withAdditional(
            array_map(fn(string $value) => $decoder->decodePath($value, '--suppress-path'), $cliExcludePaths),
            array_map(fn(string $value) => $decoder->decodeNamespace($value, '--suppress-namespace'), $cliExcludeNamespaces),
        );

        $gitScope = null;
        if ($scope->gitClient !== null && $scope->reportScope !== null) {
            $gitScope = new GitScopeRequest(
                reference: $scope->reportScope->ref,
                projectRoot: $scope->projectRoot,
                includeParentNamespaces: !(bool) $input->getOption('report-strict'),
            );
        }

        $baselinePath = CommandLineSpelling::option($input, 'baseline');

        return new FindingProjectionOptions(
            baselinePath: $baselinePath !== '' ? $baselinePath : null,
            suppressPaths: $exclusions->suppressPaths,
            suppressNamespaces: $exclusions->suppressNamespaces,
            annotationSuppressionDisabled: (bool) $input->getOption('no-suppression-annotations'),
            gitScope: $gitScope,
        );
    }

    /**
     * Loads suppressions, filters findings, and outputs filter-related messages.
     */
    public function filterAndReport(
        AnalysisResult $result,
        InputInterface $input,
        OutputInterface $output,
        ResolvedCheckScope $resolvedScope,
        FindingProjectionOptions $options,
        RunConfiguration $configuration,
    ): FindingProjectionResult {
        $scopeResolution = $resolvedScope->scope;
        $output = $this->errorStream->writer($output);
        if ($options->baselinePath !== null) {
            $options = $options->withRunCoverage(new RunCoverage(
                RunScope::record($configuration->paths, $configuration->projectRoot),
                $result->measured->coverage,
                RecordedExclusions::fromRunConfiguration($configuration),
                $configuration->projectScope->universe,
                $this->composerReader->read($configuration->projectRoot)->psr4Roots(),
                $this->projectTree,
            ), $this->ruleCoverage);
        }
        $filterResult = $this->findingProjector->project(
            [...$result->findings(), ...$this->unboundSuppressions($result, $options, $this->valueScope($result, $resolvedScope))],
            $result->directives->suppressions,
            $options,
        );

        (new BaselineFilterReporter(
            $output,
            $input->getOption('show-resolved') === true,
        ))->report(
            $filterResult,
            $scopeResolution->paths,
            $scopeResolution->projectRoot,
        );
        $this->reportSuppressedFindings($filterResult, $input, $output);
        $this->reportExclusionCounts($filterResult, $output);
        $this->reportRuleExclusions($result, $input, $output);

        return $filterResult;
    }

    /**
     * The per-value half of the run's shape, built from the scope the console
     * already measured, or `null` on a run that judges no configured value at
     * all. Both readers below build it from the same carried answer, so the
     * findings and the report's list of skipped values cannot part.
     *
     * The PSR-4 map includes `autoload-dev` whatever the run's policy, unlike
     * the coverage denominator: it is asked where a namespace lives, not which
     * roots a whole-project run must reach, and a value naming test code is
     * judged exactly by a run that analysed it. Without accepted PSR-4 roots
     * there is no declared map to locate namespace values, so none is judged.
     */
    private function valueScope(AnalysisResult $result, ResolvedCheckScope $resolvedScope): ValueScopeJudgement
    {
        $scope = $resolvedScope->scope;
        $measurement = $result->measured->projectScope ?? throw new LogicException('A pipeline result requires measured project scope');

        return new ValueScopeJudgement(
            $scope->projectRoot->value(),
            $this->composerReader->read($scope->projectRoot)->psr4Roots(),
            array_map(static fn(AbsolutePath $path): string => $path->value(), $scope->paths),
            projectDeclared: $measurement->universe->namespaceMapUsable,
            scope: $measurement->judgement(),
        );
    }

    /**
     * The project scope as the report publishes it: the measurement taken
     * before the run, and — on a run that judged configured values — every
     * value it skipped, from the same enumeration the findings below come from.
     */
    public function projectScope(
        ResolvedCheckScope $resolvedScope,
        AnalysisResult $result,
        FindingProjectionOptions $options,
    ): ReportProjectScope {
        $valueScope = $this->valueScope($result, $resolvedScope);
        $measurement = $result->measured->projectScope ?? throw new LogicException('A pipeline result requires measured project scope');
        $judgement = $measurement->judgement();
        $source = $this->composerReader->read($measurement->universe->projectRoot)->source();
        $reasons = $this->observedProjectScopeReasons->forMainSource($source);
        $namespaces = $result->measured->namespaceTree?->getAllNamespaces();
        $unjudged = $this->unboundSuppressionAudit->unjudgedValues(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $namespaces,
            $valueScope,
        );
        foreach ($judgement->excludeSelectors() as $selector) {
            if (\in_array($selector->outcome, [ExcludeSelectorOutcome::NotJudged, ExcludeSelectorOutcome::CoveredByOtherSource], true)) {
                $unjudged[] = [
                    'channel' => ProjectScopeChannels::WALK_CHANNEL,
                    'option' => 'exclude',
                    'pattern' => $selector->display,
                ];
            }
        }
        $judgedChannels = $this->unboundSuppressionAudit->judgedChannels(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $namespaces,
            $valueScope,
        );
        foreach ($judgement->excludeSelectors() as $selector) {
            if (\in_array($selector->outcome, [ExcludeSelectorOutcome::Removed, ExcludeSelectorOutcome::Unmatched, ExcludeSelectorOutcome::CoveredBySameSource], true)) {
                $judgedChannels[] = ProjectScopeChannels::WALK_CHANNEL;
                break;
            }
        }

        return ReportProjectScope::measured($measurement, $unjudged, $judgedChannels)->withReasons($reasons);
    }

    /**
     * The one seam where a configured `suppress_*` value and the universe it
     * claims to name are both in hand.
     *
     * `suppress_*` never enters the pipeline — it filters the pipeline's
     * output — so no rule can be asked whether a pattern named anything, and
     * the values arrive here already carrying `--suppress-path` and
     * `--suppress-namespace` beside the configured ones, which is exactly the
     * set the projection below is about to apply.
     *
     * The findings are handed to {@see FindingProjector::project()} with the
     * run's own, so a report, a baseline decision and an exit code treat them
     * like any other finding. They sit on the project, which has no file for a
     * path pattern to match and no namespace for a namespace pattern to
     * compare, so a finding about `suppress_paths: [Gone]` cannot be removed
     * by that very pattern.
     *
     * @return list<Finding>
     */
    private function unboundSuppressions(
        AnalysisResult $result,
        FindingProjectionOptions $options,
        ValueScopeJudgement $valueScope,
    ): array {
        return $this->unboundSuppressionAudit->findings(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $result->measured->coverage->analyzedFiles,
            $result->measured->namespaceTree?->getAllNamespaces(),
            $valueScope,
        );
    }

    private function reportSuppressedFindings(
        FindingProjectionResult $filterResult,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $suppressed = $filterResult->removedBy(FindingFilterStage::Suppression);

        if ($input->getOption('show-suppressed') !== true || $suppressed === []) {
            return;
        }

        $output->writeln('');
        $output->writeln(\sprintf(
            '<info>%d violation(s) suppressed by @qmx-ignore tags:</info>',
            \count($suppressed),
        ));

        self::listByFile($suppressed, $output);
    }

    private function reportExclusionCounts(FindingProjectionResult $filterResult, OutputInterface $output): void
    {
        if (!$output->isVerbose()) {
            return;
        }

        $counts = [
            'path exclusion patterns' => $filterResult->removedCountBy(FindingFilterStage::PathExclusion),
            'namespace exclusion patterns' => $filterResult->removedCountBy(FindingFilterStage::NamespaceExclusion),
        ];

        foreach ($counts as $patterns => $count) {
            if ($count > 0) {
                $output->writeln(\sprintf('<info>%d violation(s) suppressed by %s</info>', $count, $patterns));
            }
        }
    }

    /**
     * Reports findings suppressed by per-rule `suppress_namespaces`,
     * `suppress_namespace_channels`, or `suppress_paths` (any rule, set via
     * `rules: {<rule-name>: {...}}` in `qmx.yaml` —
     * {@see RuleExclusionStats}). Unlike the global exclusion filters above, this
     * mechanism runs inside rule execution itself, before the findings even
     * reach {@see FindingProjector}, so it needs its own reporting path. The
     * stats travel on `$result` — the same value {@see \Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult}
     * carries out of that run — rather than through a second, separately
     * mutable accessor. {@see exclusionStats()} is where a missing value is
     * refused rather than defaulted.
     */
    private function reportRuleExclusions(AnalysisResult $result, InputInterface $input, OutputInterface $output): void
    {
        $stats = $this->exclusionStats($result);

        if ($input->getOption('show-suppressed') === true && $stats->excludedFindings !== []) {
            $output->writeln('');
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by per-rule suppress_namespaces/suppress_namespace_channels/suppress_paths:</info>',
                \count($stats->excludedFindings),
            ));

            self::listByFile($stats->excludedFindings, $output);
        }

        if (!$output->isVerbose()) {
            return;
        }

        $breakdowns = [
            'suppress_paths' => [$stats->totalPathExclusions(), $stats->pathExclusionsByRule],
            'suppress_namespaces/suppress_namespace_channels' => [
                $stats->totalNamespaceExclusions(),
                $stats->namespaceExclusionsByRule,
            ],
        ];

        foreach ($breakdowns as $option => [$total, $byRule]) {
            if ($total > 0) {
                $output->writeln(\sprintf(
                    '<info>%d violation(s) suppressed by per-rule %s (%s)</info>',
                    $total,
                    $option,
                    self::formatRuleBreakdown($byRule),
                ));
            }
        }
    }

    /**
     * A missing `$result->ruleExecution` is refused rather than defaulted to
     * empty stats: the one production caller of {@see filterAndReport()} always
     * runs the real pipeline, which always sets it, so a `null` here is a
     * wiring bug — the value did not arrive — and "0 excluded" would print
     * identically to "nothing was excluded", hiding exactly the failure a
     * reader most needs to see.
     */
    private function exclusionStats(AnalysisResult $result): RuleExclusionStats
    {
        if ($result->ruleExecution === null) {
            throw new LogicException(
                'AnalysisResult::$ruleExecution is null — the pipeline did not hand a rule-execution result to'
                . ' this run, so per-rule exclusion stats cannot be reported.',
            );
        }

        return $result->ruleExecution->exclusions;
    }

    /**
     * @param list<Finding> $findings
     */
    private static function listByFile(array $findings, OutputInterface $output): void
    {
        $byFile = [];

        foreach ($findings as $finding) {
            $file = $finding->location->isNone() ? '(no file)' : $finding->location->pathString();
            $byFile[$file][] = $finding;
        }

        foreach ($byFile as $file => $fileFindings) {
            $output->writeln(\sprintf('  <comment>%s</comment>', $file));

            foreach ($fileFindings as $finding) {
                $output->writeln(\sprintf(
                    '    line %s — %s [%s]',
                    $finding->location->line ?? '?',
                    $finding->getDisplayMessage(),
                    $finding->ruleName,
                ));
            }
        }
    }

    /**
     * @param array<string, int> $countsByRule
     */
    private static function formatRuleBreakdown(array $countsByRule): string
    {
        $parts = [];

        foreach ($countsByRule as $ruleName => $count) {
            $parts[] = \sprintf('%s: %d', $ruleName, $count);
        }

        return implode(', ', $parts);
    }
}
