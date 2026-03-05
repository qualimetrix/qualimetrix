<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Helper for writing large output to console.
 *
 * Writes data line by line with flush to avoid PTY buffer truncation
 * on macOS where large output gets cut off in Terminal.app.
 */
final class OutputHelper
{
    /**
     * Writes content line by line with flush.
     *
     * Use this for any output where the size is unknown or potentially large
     * (formatted reports, exported graphs, etc.).
     *
     * @param OutputInterface $output Symfony Console output
     * @param string $content Content to write
     */
    public static function write(OutputInterface $output, string $content): void
    {
        // For small output or non-TTY (pipes), write directly
        if (\strlen($content) <= 4096 || !self::isTty($output)) {
            $output->write($content);

            return;
        }

        // For large TTY output, write line by line to avoid PTY buffer truncation
        $lines = explode("\n", $content);
        $last = array_key_last($lines);

        foreach ($lines as $i => $line) {
            $output->write($i < $last ? $line . "\n" : $line);
        }

        self::flush($output);
    }

    private static function isTty(OutputInterface $output): bool
    {
        if ($output instanceof StreamOutput) {
            return stream_isatty($output->getStream());
        }

        return false;
    }

    private static function flush(OutputInterface $output): void
    {
        if ($output instanceof StreamOutput) {
            fflush($output->getStream());
        }
    }
}
