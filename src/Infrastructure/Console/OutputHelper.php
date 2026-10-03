<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Core\FileTarget\HeldTarget;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Writes serialized console output without parsing literal markup.
 */
final class OutputHelper
{
    /**
     * Writes content to output, checking stream writes after restoring blocking mode.
     *
     * @param OutputInterface $output Symfony Console output
     * @param string $content Content to write
     */
    public static function write(OutputInterface $output, string $content): void
    {
        if ($output instanceof StreamOutput) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
                $stream = $output->getStream();
                $spelling = stream_get_meta_data($stream)['uri'] ?? 'console output stream';
                HeldTarget::writeToStream($stream, $content, $spelling);
            }

            return;
        }

        $output->write($content, false, OutputInterface::OUTPUT_RAW);
    }
}
