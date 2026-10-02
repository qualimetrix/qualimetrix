<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;

final class FileReplacement
{
    public static function replace(ResolvedTarget $target, string $bytes, ?int $mode, NewName $newName): void
    {
        if ($target->kind !== TargetKind::Regular && $target->kind !== TargetKind::Absent) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'replacement requires a regular file or absent name');
        }

        $path = $target->path?->value() ?? throw new LogicException('Replacement path is missing');
        $temporary = TemporarySibling::create(AbsolutePath::fromString(\dirname($path)));
        try {
            $handle = $temporary->handle();
            $total = \strlen($bytes);
            $offset = 0;
            while ($offset < $total) {
                $written = @fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $target->spelling, \sprintf('wrote %d of %d bytes', $offset, $total), error_get_last()['message'] ?? 'write returned no bytes');
                }
                $offset += $written;
            }
            if (!@fflush($handle)) {
                throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $target->spelling, \sprintf('wrote %d of %d bytes but flush failed', $offset, $total), error_get_last()['message'] ?? 'unknown error');
            }

            $now = TargetPath::resolve($target->spelling);
            if (!$target->sameAs($now)) {
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'target changed before replacement');
            }

            if ($mode === null && $now->kind === TargetKind::Regular) {
                $old = @lstat($path);
                if ($old === false) {
                    throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'target disappeared before replacement');
                }
                $mode = $old['mode'] & 07777;
            }
            if ($mode === null) {
                $mode = 0666 & ~umask();
            }
            if (!@chmod($temporary->path()->value(), $mode)) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot set replacement mode', error_get_last()['message'] ?? 'unknown error');
            }

            if ($now->kind === TargetKind::Absent && $newName === NewName::Exclusive) {
                if (!@link($temporary->path()->value(), $path)) {
                    clearstatcache(true, $path);
                    if (@lstat($path) !== false) {
                        throw new FileTargetFailure(FileTargetFailureKind::Appeared, $target->spelling, 'target appeared before exclusive replacement');
                    }
                    throw new FileTargetFailure(FileTargetFailureKind::NoHardLinks, $target->spelling, 'filesystem cannot create a hard link', error_get_last()['message'] ?? 'unknown error');
                }
                clearstatcache(true, $path);
                $named = @lstat($path);
                $opened = fstat($handle);
                if ($named === false || $opened === false || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
                    $temporary->cleanupLinkedReferent($path);
                    throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'exclusive replacement identity changed');
                }
            } elseif (!@rename($temporary->path()->value(), $path)) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot publish replacement', error_get_last()['message'] ?? 'unknown error');
            }
        } finally {
            $temporary->discard();
        }
    }
}
