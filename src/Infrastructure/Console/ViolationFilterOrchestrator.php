<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\RuleExecution\RuleExclusionStats;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Baseline\RunScope;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turns `check`'s options into a pipeline run and reports what the run's
 * stages did — stale baseline entries, resolved entries, inert entries,
 * a scope mismatch against the loaded baseline, suppressed and excluded
 * findings.
 *
 * This class is where `InputInterface` stops: the pipeline and
 * {@see MeasuredViolationSet} below it take values, which is what lets a
 * command with a different option surface measure the same set.
 */
final readonly class ViolationFilterOrchestrator
{
    public function __construct(
        private ViolationFilterPipeline $violationFilterPipeline,
        private RuleExecutorInterface $ruleExecutor,
    ) {}

    /**
     * Loads suppressions, filters violations, and outputs filter-related messages.
     */
    public function filterAndReport(
        AnalysisResult $result,
        InputInterface $input,
        OutputInterface $output,
        GitScopeResolution $scopeResolution,
    ): ViolationFilterResult {
        $this->violationFilterPipeline->loadSuppressions($result->suppressions);

        $filterResult = $this->violationFilterPipeline->filter(
            $result->violations,
            self::optionsFrom($input, $scopeResolution),
        );

        $this->reportBaselineEntries($filterResult, $input, $output);
        $this->reportInertEntries($filterResult, $output);
        $this->reportScopeMismatch($filterResult, $scopeResolution, $output);
        $this->reportSuppressedViolations($filterResult, $input, $output);
        $this->reportExclusionCounts($filterResult, $output);
        $this->reportRuleExclusions($input, $output);

        return $filterResult;
    }

    private static function optionsFrom(
        InputInterface $input,
        GitScopeResolution $scopeResolution,
    ): ViolationFilterOptions {
        $baselinePath = $input->getOption('baseline');
        /** @var list<string> $cliExcludePaths */
        $cliExcludePaths = $input->getOption('exclude-path');
        /** @var list<string> $cliExcludeNamespaces */
        $cliExcludeNamespaces = $input->getOption('exclude-namespace');

        $gitScope = null;
        if ($scopeResolution->gitClient !== null && $scopeResolution->reportScope !== null) {
            $gitScope = new GitScopeFilterConfig(
                gitClient: $scopeResolution->gitClient,
                reportScope: $scopeResolution->reportScope,
                strictMode: (bool) $input->getOption('report-strict'),
                projectRoot: $scopeResolution->projectRoot,
            );
        }

        return new ViolationFilterOptions(
            baselinePath: \is_string($baselinePath) && $baselinePath !== '' ? $baselinePath : null,
            narrowing: new CliOnlyNarrowing(
                excludePaths: $cliExcludePaths,
                excludeNamespaces: $cliExcludeNamespaces,
                annotationSuppressionDisabled: (bool) $input->getOption('no-suppression-annotations'),
            ),
            gitScope: $gitScope,
        );
    }

    /**
     * Reports entries whose identity the run did not measure — and does
     * nothing else with them (§5.7).
     *
     * `--show-resolved` reads the same predicate and reports the same set in
     * a different unit: entries whose group did not appear, not findings. It
     * is a presentation of staleness rather than a fourth operation, which is
     * why both are answered from one list here.
     *
     * The stale message says what was actually measured. "Symbols no longer
     * exist" was true while staleness was keyed on the symbol; under the
     * identity of §5.1 the symbol is usually still right there and one of its
     * channels simply stopped firing, which the list printed underneath makes
     * plain.
     *
     * There is deliberately no `baseline:cleanup` suggestion. That command
     * selects on a different predicate — whether the `file:` a key names is
     * gone — so for a `method:`, `class:`, `ns:` or `project:` entry it is a
     * guaranteed no-op, and advising it would send a user round a loop with
     * no exit. Removal by identity arrives with `cleanup --remove` in P4.
     */
    private function reportBaselineEntries(
        ViolationFilterResult $filterResult,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        if ($filterResult->staleEntries === []) {
            return;
        }

        $output->writeln(\sprintf(
            '<comment>%d baseline entries did not appear in this run:</comment>',
            $filterResult->staleEntryCount(),
        ));

        foreach ($filterResult->staleEntries as $entry) {
            $output->writeln(\sprintf(
                '  - %s [%s]',
                $entry->identity->describe(),
                $entry->selector()->value,
            ));
        }

        $output->writeln(
            '<comment>An entry stops appearing when its finding was repaired, or when configuration '
            . 'stopped producing it. Nothing is removed automatically; the remaining entries still apply.</comment>',
        );

        if ($input->getOption('show-resolved') === true) {
            $output->writeln(\sprintf(
                '<info>%d baseline entries have been resolved!</info>',
                $filterResult->staleEntryCount(),
            ));
        }
    }

    /**
     * Reports every entry the loaded baseline could not apply (§6): a bad
     * `channel`, an undeclared one, a shape mismatch in either direction, an
     * unrecognized `mode`, or two entries claiming one identity.
     *
     * Printed unconditionally, not behind a flag — an inert entry suppresses
     * nothing, so the findings it was meant to cover are reported at their
     * own severity with no other signal that the baseline file has a line
     * that no longer does anything. This is not a load failure and does not
     * fail the run: refusing to load would punish the whole file for one bad
     * line.
     */
    private function reportInertEntries(ViolationFilterResult $filterResult, OutputInterface $output): void
    {
        if ($filterResult->inertEntries === []) {
            return;
        }

        $output->writeln('');
        $output->writeln(\sprintf(
            '<comment>%d baseline entries could not be applied and are not suppressing anything:</comment>',
            \count($filterResult->inertEntries),
        ));

        foreach ($filterResult->inertEntries as $entry) {
            $output->writeln(\sprintf(
                '  - %s [%s]: %s — %s',
                $entry->describe(),
                $entry->selector->value,
                $entry->reason->description(),
                $entry->detail,
            ));
        }

        $output->writeln(
            '<comment>The findings these entries were meant to cover are reported at their own severity, '
            . 'not suppressed. Fix or remove the line in the baseline file to stop seeing this.</comment>',
        );
    }

    /**
     * Reports when this run's analysed paths do not cover the loaded
     * baseline's recorded `scope` (§5.7). Narrower than usual is legitimate —
     * checking one directory is the ordinary case — so this never fails the
     * run; the scope guard that refuses to run is a precondition of the
     * writing commands (`baseline:update`, `baseline:cleanup`), not of
     * `check`.
     *
     * A narrower run makes every identity outside it look absent, which is
     * exactly what the stale list above reports — so the explanation here
     * points back at it rather than duplicating the mechanism.
     *
     * The run's own scope is derived by {@see RunScope::record()} — the same
     * call the writing commands make — so this side of the guard and theirs
     * cannot disagree about what a run analysed.
     */
    private function reportScopeMismatch(
        ViolationFilterResult $filterResult,
        GitScopeResolution $scopeResolution,
        OutputInterface $output,
    ): void {
        if ($filterResult->baselineScope === null) {
            return;
        }

        $runScope = RunScope::record($scopeResolution->paths, $scopeResolution->projectRoot);
        $uncovered = $runScope->uncoveredPaths($filterResult->baselineScope);

        if ($uncovered === []) {
            return;
        }

        $output->writeln('');
        $output->writeln(\sprintf(
            '<comment>This run does not cover the baseline\'s recorded scope: %s</comment>',
            implode(', ', $uncovered),
        ));
        $output->writeln(
            '<comment>Entries under an uncovered path look absent from this run and are counted among the '
            . 'stale entries above — they are not resolved. Run against the recorded scope to see the '
            . 'baseline\'s full state.</comment>',
        );
    }

    private function reportSuppressedViolations(
        ViolationFilterResult $filterResult,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $suppressed = $filterResult->removedBy(ViolationFilterStage::Suppression);

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

    private function reportExclusionCounts(ViolationFilterResult $filterResult, OutputInterface $output): void
    {
        if (!$output->isVerbose()) {
            return;
        }

        $counts = [
            'path exclusion patterns' => $filterResult->removedCountBy(ViolationFilterStage::PathExclusion),
            'namespace exclusion patterns' => $filterResult->removedCountBy(ViolationFilterStage::NamespaceExclusion),
        ];

        foreach ($counts as $patterns => $count) {
            if ($count > 0) {
                $output->writeln(\sprintf('<info>%d violation(s) suppressed by %s</info>', $count, $patterns));
            }
        }
    }

    /**
     * Reports violations suppressed by per-rule `exclude_namespaces` / `exclude_paths`
     * (any rule, set via `rules: {<rule-name>: {...}}` in `qmx.yaml` —
     * {@see RuleExclusionStats}). Unlike the global exclusion filters above, this
     * mechanism runs inside {@see RuleExecutorInterface::execute()}, before the
     * violations even reach {@see ViolationFilterPipeline}, so it needs its own
     * reporting path.
     */
    private function reportRuleExclusions(InputInterface $input, OutputInterface $output): void
    {
        $stats = $this->ruleExecutor->getRuleExclusionStats();

        if ($input->getOption('show-suppressed') === true && $stats->excludedViolations !== []) {
            $output->writeln('');
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by per-rule exclude_namespaces/exclude_paths:</info>',
                \count($stats->excludedViolations),
            ));

            self::listByFile($stats->excludedViolations, $output);
        }

        if (!$output->isVerbose()) {
            return;
        }

        $breakdowns = [
            'exclude_paths' => [$stats->totalPathExclusions(), $stats->pathExclusionsByRule],
            'exclude_namespaces' => [$stats->totalNamespaceExclusions(), $stats->namespaceExclusionsByRule],
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
     * @param list<Violation> $violations
     */
    private static function listByFile(array $violations, OutputInterface $output): void
    {
        $byFile = [];

        foreach ($violations as $violation) {
            $file = $violation->location->isNone() ? '(no file)' : $violation->location->pathString();
            $byFile[$file][] = $violation;
        }

        foreach ($byFile as $file => $fileViolations) {
            $output->writeln(\sprintf('  <comment>%s</comment>', $file));

            foreach ($fileViolations as $violation) {
                $output->writeln(\sprintf(
                    '    line %s — %s [%s]',
                    $violation->location->line ?? '?',
                    $violation->getDisplayMessage(),
                    $violation->ruleName,
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
