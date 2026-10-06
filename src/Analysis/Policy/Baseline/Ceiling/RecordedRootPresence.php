<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/** Proves that a recorded root still exists before treating a missing file as stale. */
final class RecordedRootPresence
{
    private function __construct() {}

    public static function forFile(RelativePath $file, RunScope $recordedScope, RunCoverage $run): ProjectEntryPresence
    {
        $unknown = false;
        foreach ($recordedScope->paths() as $recorded) {
            $directory = self::recordedDirectory($recorded, $file, $run);
            if ($directory === null) {
                continue;
            }
            $absolute = str_starts_with($directory, '/')
                ? AbsolutePath::fromString($directory)
                : ($directory === '.'
                    ? $run->universe->projectRoot
                    : $run->universe->projectRoot->joinRelative(RelativePath::fromString($directory)));
            $presence = $run->hasDirectory($absolute);
            if ($presence === ProjectEntryPresence::Present) {
                return $presence;
            }
            $unknown = $unknown || $presence === ProjectEntryPresence::Unknown;
        }

        return $unknown ? ProjectEntryPresence::Unknown : ProjectEntryPresence::Absent;
    }

    private static function recordedDirectory(string $recorded, RelativePath $file, RunCoverage $run): ?string
    {
        if (str_starts_with($recorded, '/')) {
            return self::absoluteRecordedDirectory($recorded, $file, $run);
        }
        if (!RunScope::fromRecorded([$recorded])->coversPath($file->value())) {
            return null;
        }

        return $recorded === $file->value() ? \dirname($recorded) : $recorded;
    }

    private static function absoluteRecordedDirectory(string $recorded, RelativePath $file, RunCoverage $run): ?string
    {
        $currentRoot = realpath($run->universe->projectRoot->value());
        if ($currentRoot === false) {
            return null;
        }

        $subject = rtrim($currentRoot, '/') . '/' . $file->value();
        $recordedDirectory = realpath($recorded);
        if ($recordedDirectory !== false && is_dir($recorded) && str_starts_with($subject, rtrim($recordedDirectory, '/') . '/')) {
            return $recorded;
        }
        $parent = \dirname($recorded);
        $recordedParent = realpath($parent);

        return $recordedParent !== false && $subject === rtrim($recordedParent, '/') . '/' . basename($recorded)
            ? $parent
            : null;
    }
}
