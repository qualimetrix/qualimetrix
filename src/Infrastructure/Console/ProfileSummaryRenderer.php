<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary;

final class ProfileSummaryRenderer
{
    public function render(ProfileSummary $summary): string
    {
        if ($summary->spans === []) {
            return '<comment>No profiling data available</comment>';
        }
        $lines = ['<comment>Profile summary:</comment>'];
        foreach ($summary->spans as $name => $stat) {
            $line = \sprintf('  <info>%s</info>: %.3fs | %dx', $name, $stat['total'] / 1000, $stat['count']);
            if ($stat['unstopped'] > 0) {
                $line .= \sprintf(' | %d never stopped, not timed', $stat['unstopped']);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
