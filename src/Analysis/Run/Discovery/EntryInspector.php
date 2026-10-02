<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

/** Filesystem metadata for discovery and on-demand project tree queries. */
final class EntryInspector implements EntryInspectorInterface
{
    public function inspect(string $path): EntryKind
    {
        // @qmx-ignore-next-line code-smell.error-suppression -- Missing entry metadata remains StatFailed; interpreter warnings must not enter reports.
        $stat = @lstat($path);
        if ($stat === false) {
            return EntryKind::StatFailed;
        }

        $type = $stat['mode'] & 0170000;
        if ($type === 0120000) {
            return $this->inspectLink($path);
        }

        return match ($type) {
            0100000 => EntryKind::RegularFile,
            0040000 => EntryKind::Directory,
            default => EntryKind::Special,
        };
    }

    private function inspectLink(string $path): EntryKind
    {
        // @qmx-ignore-next-line code-smell.error-suppression -- An unavailable link target remains DanglingLink; the failed stat is handled explicitly.
        $target = @stat($path);
        if ($target === false) {
            return EntryKind::DanglingLink;
        }

        return match ($target['mode'] & 0170000) {
            0100000 => EntryKind::FileLink,
            0040000 => EntryKind::DirectoryLink,
            default => EntryKind::Special,
        };
    }

    public function list(string $directory): ?array
    {
        if (!$this->canList($directory)) {
            return null;
        }

        // @qmx-ignore-next-line code-smell.error-suppression -- A directory can disappear after stat; failed opening remains null rather than an empty successful listing.
        $handle = @opendir($directory);
        if ($handle === false) {
            return null;
        }

        return $this->readNames($handle);
    }

    private function canList(string $directory): bool
    {
        // @qmx-ignore-next-line code-smell.error-suppression -- Unavailable directory metadata refuses listing with null; the warning adds no usable metadata.
        $stat = @stat($directory);

        return $stat !== false && ($stat['mode'] & 0444) !== 0 && ($stat['mode'] & 0111) !== 0;
    }

    /**
     * @param resource $handle
     *
     * @return list<string>|null
     */
    private function readNames($handle): ?array
    {
        $names = [];
        $readFailed = false;
        set_error_handler(static function () use (&$readFailed): bool {
            $readFailed = true;

            return true;
        });
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name !== '.' && $name !== '..') {
                    $names[] = $name;
                }
            }
        } finally {
            restore_error_handler();
            closedir($handle);
        }
        if ($readFailed) {
            return null;
        }
        sort($names, \SORT_STRING);

        return $names;
    }
}
