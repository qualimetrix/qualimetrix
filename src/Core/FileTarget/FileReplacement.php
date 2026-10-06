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
        $temporary = self::prepareTemporary($target, $path);
        try {
            self::publishPrepared($target, $temporary, $bytes, $mode, $newName);
        } finally {
            $temporary->discard();
        }
    }

    /** @param ?callable(): void $beforePublish */
    public static function publishPrepared(
        ResolvedTarget $target,
        TemporarySibling $temporary,
        string $bytes,
        ?int $mode,
        NewName $newName,
        ?callable $beforePublish = null,
    ): void {
        $path = $target->path?->value() ?? throw new LogicException('Replacement path is missing');
        self::writeAll($target, $temporary, $bytes);
        $now = TargetPath::resolve($target->spelling, $target->membership());
        if (!$target->sameAs($now)) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'target changed before replacement');
        }

        $replacementMode = self::replacementMode($target, $now, $path, $mode);
        self::setMode($target, $temporary, $replacementMode);
        if ($beforePublish !== null) {
            $beforePublish();
        }
        self::publish($target, $now, $temporary, $path, $newName);
    }

    private static function prepareTemporary(ResolvedTarget $target, string $path): TemporarySibling
    {
        try {
            return TemporarySibling::create(AbsolutePath::fromString(\dirname($path)));
        } catch (FileTargetFailure $failure) {
            throw new FileTargetFailure(
                $failure->kind,
                $target->spelling,
                $failure->reason,
                $failure->spelling . ($failure->detail === '' ? '' : ': ' . $failure->detail),
            );
        }
    }

    private static function setMode(ResolvedTarget $target, TemporarySibling $temporary, int $replacementMode): void
    {
        [$changed, $warning] = NativeCall::attempt(static fn() => chmod($temporary->path()->value(), $replacementMode));
        if (!$changed) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot set replacement mode', $warning ?? 'unknown error');
        }
    }

    private static function writeAll(ResolvedTarget $target, TemporarySibling $temporary, string $bytes): void
    {
        $handle = $temporary->handle();
        $total = \strlen($bytes);
        $offset = 0;
        while ($offset < $total) {
            [$written, $warning] = NativeCall::attempt(static fn() => fwrite($handle, substr($bytes, $offset)));
            if ($written === false || $written === 0) {
                throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $target->spelling, \sprintf('wrote %d of %d bytes', $offset, $total), $warning ?? 'write returned no bytes');
            }
            $offset += $written;
        }
        [$flushed, $warning] = NativeCall::attempt(static fn() => fflush($handle));
        if (!$flushed) {
            throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $target->spelling, \sprintf('wrote %d of %d bytes but flush failed', $offset, $total), $warning ?? 'unknown error');
        }
    }

    private static function replacementMode(ResolvedTarget $target, ResolvedTarget $now, string $path, ?int $mode): int
    {
        if ($mode !== null) {
            return $mode;
        }
        if ($now->kind === TargetKind::Regular) {
            [$old] = NativeCall::attempt(static fn() => lstat($path));
            if ($old === false) {
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'target disappeared before replacement');
            }

            return $old['mode'] & 07777;
        }

        return 0666 & ~umask();
    }

    private static function publish(ResolvedTarget $target, ResolvedTarget $now, TemporarySibling $temporary, string $path, NewName $newName): void
    {
        if ($now->kind === TargetKind::Absent && $newName === NewName::Exclusive) {
            self::publishExclusive($target, $temporary, $path);

            return;
        }

        [$renamed, $warning] = NativeCall::attempt(static fn() => rename($temporary->path()->value(), $path));
        if (!$renamed) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot publish replacement', $warning ?? 'unknown error');
        }
    }

    private static function publishExclusive(ResolvedTarget $target, TemporarySibling $temporary, string $path): void
    {
        [$linked, $warning] = NativeCall::attempt(static fn() => link($temporary->path()->value(), $path));
        if (!$linked) {
            clearstatcache(true, $path);
            [$appeared] = NativeCall::attempt(static fn() => lstat($path));
            if ($appeared !== false) {
                throw new FileTargetFailure(FileTargetFailureKind::Appeared, $target->spelling, 'target appeared before exclusive replacement');
            }
            throw new FileTargetFailure(FileTargetFailureKind::NoHardLinks, $target->spelling, 'filesystem cannot create a hard link', $warning ?? 'unknown error');
        }
        clearstatcache(true, $path);
        [$named] = NativeCall::attempt(static fn() => lstat($path));
        $opened = fstat($temporary->handle());
        if ($named === false || $opened === false || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
            $temporary->cleanupLinkedReferent($path);
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'exclusive replacement identity changed');
        }
    }
}
