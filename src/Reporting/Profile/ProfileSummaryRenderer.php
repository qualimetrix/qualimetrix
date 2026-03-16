<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Profile;

/**
 * Formats profiling summary for console output.
 */
final class ProfileSummaryRenderer
{
    /**
     * Formats profiling summary for console output.
     *
     * @param array<string, array{total: float, count: int, avg: float, memory: int, peak_memory: int}> $summary
     */
    public function render(array $summary): string
    {
        if ($summary === []) {
            return '<comment>No profiling data available</comment>';
        }

        // Calculate total time by summing all span durations.
        // Note: percentages may sum to >100% due to overlapping/nested spans.
        // This is expected — each span's percentage shows its share of total measured work,
        // not of wall-clock time.
        $totalTime = 0.0;
        foreach ($summary as $stat) {
            $totalTime += $stat['total'];
        }

        // Sort by total time descending
        uasort($summary, fn($a, $b) => $b['total'] <=> $a['total']);

        // Filter out spans contributing less than 1% of the longest span's duration.
        // Using max span (≈ wall-clock) instead of totalTime (sum of all including nested)
        // to avoid hiding meaningful phases.
        $maxTime = max(array_column($summary, 'total'));
        $threshold = $maxTime * 0.01;
        $filtered = array_filter($summary, static fn($stat) => $stat['total'] >= $threshold);
        $hiddenCount = \count($summary) - \count($filtered);

        $lines = ['<comment>Profile summary:</comment>'];

        foreach ($filtered as $name => $stat) {
            $percentage = $totalTime > 0 ? ($stat['total'] / $totalTime) * 100 : 0;
            $memoryDelta = $this->formatBytes($stat['memory']);
            $peakMemory = $this->formatBytes($stat['peak_memory']);

            $lines[] = \sprintf(
                '  <info>%s</info>: %.3fs (%3.0f%%) | Δ%s | ↑%s | %dx',
                str_pad($name, 15),
                $stat['total'] / 1000, // ms to s
                $percentage,
                str_pad($memoryDelta, 8),
                str_pad($peakMemory, 8),
                $stat['count'],
            );
        }

        if ($hiddenCount > 0) {
            $lines[] = \sprintf('  <comment>... and %d more spans below 1%%</comment>', $hiddenCount);
        }

        // Add peak memory
        $peakMemory = memory_get_peak_usage(true);
        $lines[] = \sprintf('<comment>Peak memory:</comment> %s', $this->formatBytes($peakMemory));

        return implode("\n", $lines);
    }

    /**
     * Formats bytes to human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 0) {
            return \sprintf('%d B', $bytes);
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, \count($units) - 1);

        $bytes /= (1024 ** $pow);

        return \sprintf('%.1f %s', $bytes, $units[(int) $pow]);
    }
}
