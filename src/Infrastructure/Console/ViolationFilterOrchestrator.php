<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Orchestrates violation filtering and outputs filter-related messages.
 *
 * Combines ViolationFilterPipeline execution with CLI output for
 * stale baselines, resolved violations, suppression stats, and git scope notes.
 *
 * @qmx-threshold complexity.npath method.error=3000
 * @qmx-threshold complexity.cyclomatic method.error=25
 * Orchestrator method handles many filter stages with output — complexity is structural.
 */
final readonly class ViolationFilterOrchestrator
{
    public function __construct(
        private ViolationFilterPipeline $violationFilterPipeline,
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
            );
        }

        $options = new ViolationFilterOptions(
            baselinePath: \is_string($baselinePath) && $baselinePath !== '' ? $baselinePath : null,
            ignoreStaleBaseline: (bool) $input->getOption('baseline-ignore-stale'),
            disableSuppression: (bool) $input->getOption('no-suppression'),
            excludePaths: $cliExcludePaths,
            excludeNamespaces: $cliExcludeNamespaces,
            gitScope: $gitScope,
        );

        $filterResult = $this->violationFilterPipeline->filter($result->violations, $options);

        if ($filterResult->staleBaselineKeys !== []) {
            $this->handleStaleBaselineOutput($filterResult, $options, $output);
        }

        if ($input->getOption('show-resolved') && $filterResult->baselineFilter !== null) {
            $resolved = $filterResult->baselineFilter->getResolvedFromBaseline($result->violations);
            $resolvedCount = array_sum(array_map(count(...), $resolved));

            if ($resolvedCount > 0) {
                $output->writeln(\sprintf(
                    '<info>%d violations from baseline have been resolved!</info>',
                    $resolvedCount,
                ));
            }
        }

        if ($input->getOption('show-suppressed') && $filterResult->suppressedViolations !== []) {
            $output->writeln('');
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by @qmx-ignore tags:</info>',
                \count($filterResult->suppressedViolations),
            ));

            $byFile = [];
            foreach ($filterResult->suppressedViolations as $v) {
                $file = $v->location->file ?: '(no file)';
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

        return $filterResult;
    }

    private function handleStaleBaselineOutput(
        ViolationFilterResult $filterResult,
        ViolationFilterOptions $options,
        OutputInterface $output,
    ): void {
        if ($options->ignoreStaleBaseline) {
            $output->writeln(\sprintf(
                '<comment>Warning: Baseline contains %d stale entries (symbols no longer exist)</comment>',
                $filterResult->staleBaselineCount,
            ));
            $output->writeln(\sprintf(
                '<comment>Run `bin/qmx baseline:cleanup %s` to remove them.</comment>',
                $options->baselinePath ?? '',
            ));

            return;
        }

        $output->writeln(\sprintf(
            '<error>Error: Baseline contains %d stale entries (symbols no longer exist):</error>',
            $filterResult->staleBaselineCount,
        ));
        foreach ($filterResult->staleBaselineKeys as $key) {
            $output->writeln(\sprintf('  - %s', $key));
        }
        $output->writeln('');
        $output->writeln(\sprintf('Run `bin/qmx baseline:cleanup %s` to remove stale entries.', $options->baselinePath ?? ''));
        $output->writeln('Or use --baseline-ignore-stale to continue anyway.');

        throw new InvalidArgumentException('Baseline contains stale entries');
    }
}
