<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\RuleExecution\RuleExclusionStats;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Orchestrates violation filtering and outputs filter-related messages.
 *
 * Combines ViolationFilterPipeline execution with CLI output for
 * stale baselines, resolved violations, suppression stats, and git scope notes.
 *
 * @qmx-threshold complexity.npath error=3000 — Coordinates independent filter stages and their CLI output.
 * @qmx-threshold complexity.cyclomatic error=25 — Coordinates independent filter stages and their CLI output.
 * Orchestrator method handles many filter stages with output — complexity is structural.
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

        $options = new ViolationFilterOptions(
            baselinePath: \is_string($baselinePath) && $baselinePath !== '' ? $baselinePath : null,
            disableSuppression: (bool) $input->getOption('no-suppression'),
            excludePaths: $cliExcludePaths,
            excludeNamespaces: $cliExcludeNamespaces,
            gitScope: $gitScope,
        );

        $filterResult = $this->violationFilterPipeline->filter($result->violations, $options);

        if ($filterResult->staleBaselineKeys !== []) {
            $this->reportStaleBaselineEntries($filterResult, $output);
        }

        if ($input->getOption('show-resolved') === true && $filterResult->baselineFilter !== null) {
            $resolvedCount = \count($filterResult->baselineFilter->getResolvedFromBaseline($result->violations));

            if ($resolvedCount > 0) {
                $output->writeln(\sprintf(
                    '<info>%d baseline entries have been resolved!</info>',
                    $resolvedCount,
                ));
            }
        }

        if ($input->getOption('show-suppressed') === true && $filterResult->suppressedViolations !== []) {
            $output->writeln('');
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by @qmx-ignore tags:</info>',
                \count($filterResult->suppressedViolations),
            ));

            $byFile = [];
            foreach ($filterResult->suppressedViolations as $v) {
                $file = $v->location->isNone() ? '(no file)' : $v->location->pathString();
                $byFile[$file][] = $v;
            }

            foreach ($byFile as $file => $violations) {
                $output->writeln(\sprintf('  <comment>%s</comment>', $file));
                foreach ($violations as $v) {
                    $output->writeln(\sprintf(
                        '    line %s — %s [%s]',
                        $v->location->line ?? '?',
                        $v->getDisplayMessage(),
                        $v->ruleName,
                    ));
                }
            }
        }

        if ($filterResult->pathExclusionFiltered > 0 && $output->isVerbose()) {
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by path exclusion patterns</info>',
                $filterResult->pathExclusionFiltered,
            ));
        }

        if ($filterResult->namespaceExclusionFiltered > 0 && $output->isVerbose()) {
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by namespace exclusion patterns</info>',
                $filterResult->namespaceExclusionFiltered,
            ));
        }

        $this->reportRuleExclusions($input, $output);

        return $filterResult;
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

            $byFile = [];
            foreach ($stats->excludedViolations as $v) {
                $file = $v->location->isNone() ? '(no file)' : $v->location->pathString();
                $byFile[$file][] = $v;
            }

            foreach ($byFile as $file => $violations) {
                $output->writeln(\sprintf('  <comment>%s</comment>', $file));
                foreach ($violations as $v) {
                    $output->writeln(\sprintf(
                        '    line %s — %s [%s]',
                        $v->location->line ?? '?',
                        $v->getDisplayMessage(),
                        $v->ruleName,
                    ));
                }
            }
        }

        if ($stats->totalPathExclusions() > 0 && $output->isVerbose()) {
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by per-rule exclude_paths (%s)</info>',
                $stats->totalPathExclusions(),
                $this->formatRuleBreakdown($stats->pathExclusionsByRule),
            ));
        }

        if ($stats->totalNamespaceExclusions() > 0 && $output->isVerbose()) {
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by per-rule exclude_namespaces (%s)</info>',
                $stats->totalNamespaceExclusions(),
                $this->formatRuleBreakdown($stats->namespaceExclusionsByRule),
            ));
        }
    }

    /**
     * @param array<string, int> $countsByRule
     */
    private function formatRuleBreakdown(array $countsByRule): string
    {
        $parts = [];
        foreach ($countsByRule as $ruleName => $count) {
            $parts[] = \sprintf('%s: %d', $ruleName, $count);
        }

        return implode(', ', $parts);
    }

    /**
     * Reports entries whose identity the run did not measure — and does
     * nothing else with them (§5.7).
     *
     * The message says what was actually measured. "Symbols no longer exist"
     * was true while staleness was keyed on the symbol; under the identity of
     * §5.1 the symbol is usually still right there and one of its channels
     * simply stopped firing, which the list printed underneath makes plain.
     *
     * There is deliberately no `baseline:cleanup` suggestion. That command
     * selects on a different predicate — whether the `file:` a key names is
     * gone — so for a `method:`, `class:`, `ns:` or `project:` entry it is a
     * guaranteed no-op, and advising it would send a user round a loop with
     * no exit. Removal by identity arrives with `cleanup --remove` in P4.
     */
    private function reportStaleBaselineEntries(
        ViolationFilterResult $filterResult,
        OutputInterface $output,
    ): void {
        $output->writeln(\sprintf(
            '<comment>%d baseline entries did not appear in this run:</comment>',
            $filterResult->staleBaselineCount,
        ));

        foreach ($filterResult->staleBaselineKeys as $key) {
            $output->writeln(\sprintf('  - %s', $key));
        }

        $output->writeln(
            '<comment>An entry stops appearing when its finding was repaired, or when configuration '
            . 'stopped producing it. Nothing is removed automatically; the remaining entries still apply.</comment>',
        );
    }
}
