<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Helper for writing output to console.
 *
 * Restores blocking mode on the output stream before writing. amphp/parallel
 * sets STDOUT to non-blocking via WritableResourceStream, and does not restore
 * it after worker communication ends. Non-blocking fwrite() can silently produce
 * partial writes, causing output truncation.
 */
final class OutputHelper
{
    /**
     * Writes content to output, ensuring the stream is in blocking mode.
     *
     * @param OutputInterface $output Symfony Console output
     * @param string $content Content to write
     */
    public static function write(OutputInterface $output, string $content): void
    {
        // amphp sets STDOUT to non-blocking for its event loop (WritableResourceStream).
        // After parallel processing completes, the stream remains non-blocking.
        // Symfony's @fwrite() suppresses errors, so partial writes go undetected
        // and output gets silently truncated. Restore blocking mode before writing.
        if ($output instanceof StreamOutput) {
            stream_set_blocking($output->getStream(), true);
        }

        $output->write($content);
    }
}
