<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Terminal\ReportPublication;

use Qualimetrix\Core\FileTarget\Publication\HeldTarget;

use Qualimetrix\Infrastructure\Console\Application\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Writes serialized console output without parsing literal markup.
 */
final class OutputHelper
{
    /**
     * Publishes a command answer at QUIET verbosity, preserving raw bytes and checked stream writes.
     * Application normalizes --silent to QUIET, so its answers survive too.
     *
     * @param OutputInterface $output Symfony Console output
     * @param string $content Content to write
     */
    public static function write(OutputInterface $output, string $content): void
    {
        if ($output->getVerbosity() < OutputInterface::VERBOSITY_QUIET) {
            return;
        }
        if ($output instanceof StreamOutput) {
            $stream = $output->getStream();
            $spelling = stream_get_meta_data($stream)['uri'] ?? 'console output stream';
            HeldTarget::writeToStream($stream, $content, $spelling);

            return;
        }

        $output->write($content, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }
}
