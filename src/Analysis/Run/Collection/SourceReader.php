<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Collection;

use SplFileInfo;

/** Reads one eligible source candidate into one immutable run snapshot. */
final class SourceReader
{
    public function read(SplFileInfo $file): string|UnreadableSource
    {
        if (!$file->isFile()) {
            return new UnreadableSource('File does not exist or is not a regular file');
        }
        if (!$file->isReadable()) {
            return new UnreadableSource('File is not readable');
        }

        $source = @file_get_contents($file->getPathname());

        return $source === false ? new UnreadableSource('Failed to read file contents') : $source;
    }
}
