<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

/** Filesystem metadata for discovery and on-demand project tree queries. */
final class EntryInspector implements EntryInspectorInterface
{
    public function inspect(string $path): EntryKind
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return EntryKind::StatFailed;
        }

        $type = $stat['mode'] & 0170000;
        if ($type === 0120000) {
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

        return match ($type) {
            0100000 => EntryKind::RegularFile,
            0040000 => EntryKind::Directory,
            default => EntryKind::Special,
        };
    }

    public function list(string $directory): ?array
    {
        $stat = @stat($directory);
        if ($stat === false || ($stat['mode'] & 0444) === 0 || ($stat['mode'] & 0111) === 0) {
            return null;
        }

        $handle = @opendir($directory);
        if ($handle === false) {
            return null;
        }

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
