<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class BaselineFilterReporter
{
    public function __construct(
        private OutputInterface $output,
        private bool $showResolved,
    ) {}

    /** @param list<AbsolutePath> $paths */
    public function report(
        FindingProjectionResult $filterResult,
        array $paths,
        AbsolutePath $projectRoot,
    ): void {
        $this->reportBaselineEntries($filterResult);
        $this->reportUncomparedEntries($filterResult);
        $this->reportInertEntries($filterResult);
        $this->reportScopeMismatch($filterResult, $paths, $projectRoot);
    }

    private function reportUncomparedEntries(FindingProjectionResult $filterResult): void
    {
        $outcome = $filterResult->ceilingOutcome;
        if ($outcome === null) {
            return;
        }

        foreach ([
            'unmeasured' => $outcome->unmeasuredEntries,
            'outside coverage' => $outcome->outsideCoverageEntries,
            'not compared' => $outcome->notComparedEntries,
        ] as $status => $entries) {
            foreach ($entries as $entry) {
                $this->output->writeln(\sprintf(
                    '<comment>Baseline entry %s [%s] is %s (%s).</comment>',
                    $entry->identity->describe(),
                    $entry->selector()->value,
                    $status,
                    $outcome->reasonFor($entry->identity) ?? 'unknown reason',
                ));
            }
        }
    }

    /**
     * Reports entries whose identity the run did not measure — and does
     * nothing else with them (ADR 0017).
     *
     * `--show-resolved` reads the same predicate and reports the same set in
     * a different unit: entries whose group did not appear, not findings. It
     * is a presentation of staleness rather than a fourth operation, which is
     * why both are answered from one list here.
     *
     * The stale message says what was actually measured. "Symbols no longer
     * exist" was true while staleness was keyed on the symbol; under the
     * identity of ADR 0017 the symbol is usually still right there and one of its
     * channels simply stopped firing, which the list printed underneath makes
     * plain. A moved declaration is named as the third cause because it is the
     * one a reader cannot infer from the entry: the other two are about the
     * finding, this one is about the key (ADR 0026).
     *
     * There is deliberately no `baseline:cleanup` suggestion. That command
     * selects on a different predicate — whether the `file:` a key names is
     * gone — so for a `callable:`, `class:`, `ns:` or `project:` entry it is a
     * guaranteed no-op, and advising it would send a user round a loop with
     * no exit. Removal must address the complete entry identity.
     */
    private function reportBaselineEntries(
        FindingProjectionResult $filterResult,
    ): void {
        if ($filterResult->staleEntries === []) {
            return;
        }

        $this->output->writeln(\sprintf(
            '<comment>%d baseline entries did not appear in this run:</comment>',
            $filterResult->staleEntryCount(),
        ));

        foreach ($filterResult->staleEntries as $entry) {
            $this->output->writeln(\sprintf(
                '  - %s [%s]',
                $entry->identity->describe(),
                $entry->selector()->value,
            ));
        }

        $this->output->writeln(
            '<comment>An entry stops appearing when its finding was repaired, when configuration '
            . 'stopped producing it, or when the declaration it names is no longer that declaration: '
            . 'renamed, moved to another file, or renumbered because a sibling it is counted against was '
            . 'added, removed or moved — another declaration of the same logical identity, or, for a closure '
            . 'or a member of an anonymous class, another unnamed declaration of its kind in that file. '
            . 'Nothing is removed automatically; the remaining entries still apply.</comment>',
        );

        if ($this->showResolved) {
            $this->output->writeln(\sprintf(
                '<info>%d baseline entries have been resolved!</info>',
                $filterResult->staleEntryCount(),
            ));
        }
    }

    /**
     * Reports every entry the loaded baseline could not apply (ADR 0017): a bad
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
    private function reportInertEntries(FindingProjectionResult $filterResult): void
    {
        if ($filterResult->inertEntries === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln(\sprintf(
            '<comment>%d baseline entries could not be applied and are not suppressing anything:</comment>',
            \count($filterResult->inertEntries),
        ));

        foreach ($filterResult->inertEntries as $entry) {
            $this->output->writeln(\sprintf(
                '  - %s [%s]: %s — %s',
                $entry->describe(),
                $entry->selector->value,
                $entry->reason->description(),
                $entry->detail,
            ));
        }

        $this->output->writeln(
            '<comment>The findings these entries were meant to cover are reported at their own severity, '
            . 'not suppressed. Fix or remove the line in the baseline file to stop seeing this.</comment>',
        );
    }

    /**
     * Reports when this run's analysed paths do not cover the loaded
     * baseline's recorded `scope` (ADR 0017). Narrower than usual is legitimate —
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
     *
     * @param list<AbsolutePath> $paths
     */
    private function reportScopeMismatch(
        FindingProjectionResult $filterResult,
        array $paths,
        AbsolutePath $projectRoot,
    ): void {
        if ($filterResult->baselineScope === null) {
            return;
        }

        $runScope = RunScope::record($paths, $projectRoot);
        $uncovered = $runScope->uncoveredPaths($filterResult->baselineScope);

        if ($uncovered === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln(\sprintf(
            '<comment>This run does not cover the baseline\'s recorded scope: %s</comment>',
            implode(', ', $uncovered),
        ));
        $this->output->writeln(
            '<comment>Entries under an uncovered path are outside this run and are not resolved. '
            . 'Run against the recorded scope to see the '
            . 'baseline\'s full state.</comment>',
        );
    }

}
