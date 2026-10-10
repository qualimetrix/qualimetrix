<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class BaselineFilterReporter
{
    public function __construct(private OutputInterface $output, private bool $showResolved) {}

    /** @param list<AbsolutePath> $paths */
    public function report(FindingProjectionResult $filterResult, array $paths, AbsolutePath $projectRoot): void
    {
        if ($this->showResolved && $filterResult->staleEntryCount() > 0) {
            $this->output->writeln(\sprintf('<info>%d baseline entries have been resolved!</info>', $filterResult->staleEntryCount()));
        }
        $unused = $filterResult->unselectedUnusedEntries();
        if ($unused['stale'] + $unused['inert'] > 0) {
            $this->output->writeln(\sprintf(
                '<comment>%d baseline entries are unused (%d stale, %d inert); rule %s is not selected in this run.</comment>',
                $unused['stale'] + $unused['inert'],
                $unused['stale'],
                $unused['inert'],
                BaselineAuditChannels::UNUSED_ENTRY,
            ));
        }
        $this->reportUncomparedEntries($filterResult);
        $this->reportScopeMismatch($filterResult, $paths, $projectRoot);
    }

    private function reportUncomparedEntries(FindingProjectionResult $filterResult): void
    {
        $outcome = $filterResult->ceilingOutcome;
        if ($outcome === null) {
            return;
        }
        foreach ([
            'were not measured by this run' => $outcome->unmeasuredEntries,
            'lie outside this run\'s coverage' => $outcome->outsideCoverageEntries,
            'were not compared' => $outcome->notComparedEntries,
        ] as $status => $entries) {
            $counts = [];
            foreach ($entries as $entry) {
                $reason = $outcome->reasonFor($entry->identity) ?? 'unknown reason';
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
            foreach ($counts as $reason => $count) {
                $this->output->writeln(\sprintf('<comment>%d baseline entries %s: %s.</comment>', $count, $status, $reason));
            }
        }
    }

    /** @param list<AbsolutePath> $paths */
    private function reportScopeMismatch(FindingProjectionResult $filterResult, array $paths, AbsolutePath $projectRoot): void
    {
        if ($filterResult->baselineScope === null) {
            return;
        }
        $uncovered = RunScope::record($paths, $projectRoot)->uncoveredPaths($filterResult->baselineScope);
        if ($uncovered !== []) {
            $this->output->writeln(\sprintf(
                '<comment>This run does not cover %d recorded baseline paths; entries outside coverage are not resolved.</comment>',
                \count($uncovered),
            ));
        }
    }
}
