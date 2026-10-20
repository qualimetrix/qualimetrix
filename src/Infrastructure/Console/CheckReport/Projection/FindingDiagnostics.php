<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport\Projection;

use LogicException;

use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Infrastructure\Console\CheckScope\ResolvedCheckScope;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @qmx-threshold coupling.instability 0.818183 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
final readonly class FindingDiagnostics
{
    public function __construct(private ErrorStream $errorStream) {}

    public function report(AnalysisResult $result, FindingProjectionResult $filterResult, InputInterface $input, OutputInterface $output, ResolvedCheckScope $scope): void
    {
        $output = $this->errorStream->writer($output);
        (new BaselineFilterReporter($output, $input->getOption('show-resolved') === true))->report($filterResult, $scope->scope->paths, $scope->scope->projectRoot);
        $this->reportSuppressedFindings($filterResult, $input, $output);
        $this->reportExclusionCounts($filterResult, $output);
        $this->reportRuleExclusions($result, $input, $output);
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
            '<info>%d violation(s) suppressed by @qmx-ignore tags%s:</info>',
            \count($suppressed),
            self::suppressionScopeSuffix($input),
        ));

        self::listByFile($suppressed, $output);
    }

    private static function suppressionScopeSuffix(InputInterface $input): string
    {
        foreach (['namespace', 'class'] as $selector) {
            if ($input->hasOption($selector) && $input->getOption($selector) !== null) {
                return ' across the whole run (reporting selectors are not applied)';
            }
        }

        return '';
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

    private function reportRuleExclusions(AnalysisResult $result, InputInterface $input, OutputInterface $output): void
    {
        $stats = $this->exclusionStats($result);

        if ($input->getOption('show-suppressed') === true && $stats->excludedFindings !== []) {
            $output->writeln('');
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by per-rule suppress_namespaces/suppress_namespace_channels/suppress_paths%s:</info>',
                \count($stats->excludedFindings),
                self::suppressionScopeSuffix($input),
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

    /** @param list<Finding> $findings */
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
                    $finding->message,
                    $finding->ruleName,
                ));
            }
        }
    }

    /** @param array<string, int> $countsByRule */
    private static function formatRuleBreakdown(array $countsByRule): string
    {
        $parts = [];

        foreach ($countsByRule as $ruleName => $count) {
            $parts[] = \sprintf('%s: %d', $ruleName, $count);
        }

        return implode(', ', $parts);
    }
}
