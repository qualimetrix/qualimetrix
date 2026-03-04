<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Helper for writing large output to console.
 *
 * Writes data in chunks with explicit flush to avoid pipe buffer issues
 * on PHP 8.5/macOS where output > 64KB gets truncated.
 */
final class OutputHelper
{
    private const CHUNK_SIZE = 8192;

    /**
     * Writes content in chunks with flush.
     *
     * Use this for any output where the size is unknown or potentially large
     * (formatted reports, exported graphs, etc.).
     *
     * @param OutputInterface $output Symfony Console output
     * @param string $content Content to write
     */
    public static function write(OutputInterface $output, string $content): void
    {
        $length = \strlen($content);

        // Small output — write directly
        if ($length <= self::CHUNK_SIZE) {
            $output->write($content);

            return;
        }

        // Large output — write in chunks to avoid pipe buffer issues
        for ($offset = 0; $offset < $length; $offset += self::CHUNK_SIZE) {
            $output->write(substr($content, $offset, self::CHUNK_SIZE));
        }
    }
}
