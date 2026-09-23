<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\DrillDown;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * The run's findings a `--namespace` or `--class` selection left out of the
 * report, counted by severity.
 *
 * A drill-down narrows what is shown, never what decides the exit code, so a
 * report built from the selection alone reads "0 errors" in green over a run
 * that exits 2. These counts are what lets a renderer say the selection is
 * clean while the run is not.
 *
 * Every structured format publishes them: a document with a summary under a
 * key of its own, a list format as one diagnostic entry in the channel it
 * already uses for {@see \Qualimetrix\Reporting\Formatter\PublishedUtf8}.
 */
final readonly class OutOfScopeFindings
{
    /** Check name / descriptor / source suffix the list formats publish the entry under. */
    public const string CHECK = 'drill-down.out-of-scope';

    public function __construct(
        public int $errorCount,
        public int $warningCount,
        public int $infoCount,
    ) {}

    /**
     * @param list<Finding> $run every finding of the run
     * @param list<Finding> $selected the subset the selection kept
     */
    public static function between(array $run, array $selected): self
    {
        $counts = self::countBySeverity($run);
        foreach (self::countBySeverity($selected) as $severity => $count) {
            $counts[$severity] -= $count;
        }

        return new self(
            $counts[Severity::Error->value],
            $counts[Severity::Warning->value],
            $counts[Severity::Info->value],
        );
    }

    public function total(): int
    {
        return $this->errorCount + $this->warningCount + $this->infoCount;
    }

    /**
     * The sentence every list format uses for its entry.
     */
    public function describe(): string
    {
        return \sprintf(
            '%d finding(s) outside the --namespace/--class selection (%d error(s), %d warning(s), %d info)'
            . ' are not listed in this report; the exit code is resolved over them as well.',
            $this->total(),
            $this->errorCount,
            $this->warningCount,
            $this->infoCount,
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    private static function countBySeverity(array $findings): array
    {
        $counts = [Severity::Error->value => 0, Severity::Warning->value => 0, Severity::Info->value => 0];
        foreach ($findings as $finding) {
            ++$counts[$finding->severity->value];
        }

        return $counts;
    }
}
