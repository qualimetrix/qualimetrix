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
            $lines[] = \sprintf('  <info>%s</info>: %.3fs | %dx', $name, $stat['total'] / 1000, $stat['count']);
        }
        return implode("\n", $lines);
    }
}
