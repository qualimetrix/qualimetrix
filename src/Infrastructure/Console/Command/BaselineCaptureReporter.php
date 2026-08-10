<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Baseline\BaselineCapture;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Names the findings a capture refused to record.
 *
 * `baseline:generate` reports this because without it its success line is the
 * whole story, and for a run whose findings all sit on
 * non-baselineable channels that story is "Baseline with 0 entries written",
 * followed by a `check` that reports everything the user believed they had
 * just accepted.
 */
final class BaselineCaptureReporter
{
    public static function reportUncaptured(BaselineCapture $capture, OutputInterface $output): void
    {
        if ($capture->uncaptured === []) {
            return;
        }

        $findings = 0;
        foreach ($capture->uncaptured as $group) {
            $findings += $group->memberCount;
        }

        $output->writeln(\sprintf(
            '<comment>%d finding(s) in %d group(s) were not recorded and will be reported again: %s</comment>',
            $findings,
            \count($capture->uncaptured),
            implode(', ', $capture->uncapturedChannels()),
        ));

        foreach (self::reasonCounts($capture) as $reason => $groups) {
            $output->writeln(\sprintf('<comment>  %d group(s): %s</comment>', $groups, $reason));
        }
    }

    /**
     * @return array<string, int>
     */
    private static function reasonCounts(BaselineCapture $capture): array
    {
        $counts = [];

        foreach ($capture->uncaptured as $group) {
            $reason = $group->reason->describe();
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts, \SORT_STRING);

        return $counts;
    }
}
