<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Coverage;

/**
 * A terminal failure category for one discovered file.
 *
 * The last three are reached before a file is ever read: they say that an
 * entry the run was pointed at yielded no measurements and why. They are
 * failures rather than a separate bucket because the consequence is the same
 * one every reader already knows how to act on — the run does not cover
 * everything it was asked to cover.
 */
enum AnalysisFailureKind: string
{
    case Parse = 'parse';
    case Processing = 'processing';

    /** A symbolic link to a directory: its subtree is not traversed. */
    case DirectorySymlink = 'directory-symlink';

    /** A candidate that is not a regular file: FIFO, socket, dangling link. */
    case NotRegularFile = 'not-regular-file';

    /** A directory the run could not look inside. */
    case UnreadableDirectory = 'unreadable-directory';
    case FileSymlink = 'file-symlink';
    case UnreadableFile = 'unreadable-file';
    case UnreadableEntry = 'unreadable-entry';

    /**
     * Whether the failed entry may be a directory whose contents the run never
     * saw. An unreadable entry is of unknown type, so it counts as one.
     */
    public function hidesEntriesBeneath(): bool
    {
        return match ($this) {
            self::DirectorySymlink, self::UnreadableDirectory, self::UnreadableEntry => true,
            self::Parse, self::Processing, self::NotRegularFile, self::FileSymlink, self::UnreadableFile => false,
        };
    }
}
