<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Discovery;

use SplFileInfo;

/**
 * Filters out files containing @generated annotation.
 *
 * Reads the first 2KB of each file and checks for `@generated` (case-insensitive).
 * This prevents false positives on YACC-generated parsers, Composer autoloaders,
 * protobuf output, and other generated code.
 */
final class GeneratedFileFilter
{
    /**
     * Number of bytes to read from the beginning of each file.
     * 2KB is enough to cover file headers, docblocks, and annotation comments.
     */
    private const int HEADER_BYTES = 2048;

    /**
     * Filters out generated files from a list of discovered files.
     *
     * @param list<SplFileInfo> $files
     *
     * @return list<SplFileInfo>
     */
    public function filter(array $files): array
    {
        return array_values(array_filter($files, fn(SplFileInfo $file): bool => !$this->isGenerated($file)));
    }

    /**
     * Checks if a file is marked as generated.
     *
     * Reads the first 2KB and performs a case-insensitive search for `@generated`.
     */
    public function isGenerated(SplFileInfo $file): bool
    {
        $path = $file->getPathname();

        if (!is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, self::HEADER_BYTES);
        fclose($handle);

        if ($header === false || $header === '') {
            return false;
        }

        return stripos($header, '@generated') !== false;
    }
}
