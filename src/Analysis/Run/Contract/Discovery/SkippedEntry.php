<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailureKind;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;

/**
 * One entry discovery refused to hand on, and why.
 *
 * "Entry" rather than "file": a directory symlink and an unreadable directory
 * are the cases that cost the most code, and calling either a file is what let
 * a directory be counted as an analyzed file in the first place.
 */
final readonly class SkippedEntry
{
    public function __construct(
        public AbsolutePath $path,
        public AnalysisFailureKind $reason,
        public string $detail,
    ) {}

    public static function nonRegular(AbsolutePath $path, string $detail): self
    {
        return new self($path, AnalysisFailureKind::NotRegularFile, $detail);
    }

    public static function directorySymlink(AbsolutePath $path, string $detail): self
    {
        return new self($path, AnalysisFailureKind::DirectorySymlink, $detail);
    }

    public static function fileSymlink(AbsolutePath $path, string $detail): self
    {
        return new self($path, AnalysisFailureKind::FileSymlink, $detail);
    }

    public static function unreadableEntry(AbsolutePath $path, string $detail): self
    {
        return new self($path, AnalysisFailureKind::UnreadableEntry, $detail);
    }

    public static function unreadableDirectory(AbsolutePath $path, string $detail): self
    {
        return new self($path, AnalysisFailureKind::UnreadableDirectory, $detail);
    }

    public static function unreadableFile(AbsolutePath $path, string $detail): self
    {
        return new self($path, AnalysisFailureKind::UnreadableFile, $detail);
    }

    /** The entry's written name is retained even when it is a symlink. */
    public function relativeTo(AbsolutePath $projectRoot): RelativePath
    {
        return PathFactory::published($this->path, $projectRoot);
    }
}
