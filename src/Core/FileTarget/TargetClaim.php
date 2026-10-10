<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

final class TargetClaim
{
    /** @return array{ResolvedTarget, resource, bool} */
    public static function open(ResolvedTarget $judged): array
    {
        $now = TargetPath::resolve($judged->spelling, $judged->membership());
        if (!$judged->sameAs($now)) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $judged->spelling, 'target changed before it could be claimed');
        }

        if ($now->kind === TargetKind::Absent) {
            return self::createName($now);
        }
        if ($now->kind === TargetKind::Stream && $now->streamExposed) {
            throw new FileTargetFailure(FileTargetFailureKind::ExposedStream, $judged->spelling, 'stream path can be changed by another user');
        }

        return self::openExisting($now);
    }

    /** @return array{ResolvedTarget, resource, bool} */
    private static function createName(ResolvedTarget $target): array
    {
        $path = $target->path?->value() ?? throw new LogicException('Absent target has no path');
        $temporary = self::prepareTemporary($target, $path);
        try {
            [$linked, $linkWarning] = NativeCall::attempt(static fn() => link($temporary->path()->value(), $path));
            if (!$linked) {
                self::linkFailure($path, $linkWarning);
            }
            clearstatcache(true, $path);
            [$named] = NativeCall::attempt(static fn() => lstat($path));
            $opened = fstat($temporary->handle());
            if ($named === false || $opened === false || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
                $temporary->cleanupLinkedReferent($path);
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'new target identity changed after linking');
            }
            $temporaryPath = $temporary->path()->value();
            [$removed, $warning] = NativeCall::attempt(static fn() => unlink($temporaryPath));
            if (!$removed) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot remove temporary link', $warning ?? 'unknown error');
            }

            return [$target, $temporary->takeHandle(), true];
        } finally {
            $temporary->discard();
        }
    }

    private static function prepareTemporary(ResolvedTarget $target, string $path): TemporarySibling
    {
        try {
            return TemporarySibling::create(AbsolutePath::fromString(\dirname($path)), 0666);
        } catch (FileTargetFailure $failure) {
            throw new FileTargetFailure(
                $failure->kind,
                $target->spelling,
                $failure->reason,
                $failure->spelling . ($failure->detail === '' ? '' : ': ' . $failure->detail),
            );
        }
    }

    /** @return array{ResolvedTarget, resource, bool} */
    private static function openExisting(ResolvedTarget $target): array
    {
        $path = $target->kind === TargetKind::Descriptor
            ? 'php://fd/' . $target->descriptor
            : ($target->path?->value() ?? throw new LogicException('Target path is missing'));
        $opening = $target->kind === TargetKind::Regular ? 'r+e' : 'we';
        [$handle, $warning] = NativeCall::attempt(static fn() => fopen($path, $opening));
        if ($handle === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot open target', $warning ?? 'unknown error');
        }

        try {
            self::enableBlocking($target, $handle);
            if ($target->kind !== TargetKind::Descriptor) {
                self::verifyOpenedIdentity($target, $path, $handle);
            }

            return [$target, $handle, false];
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }

    /** @param resource $handle */
    private static function enableBlocking(ResolvedTarget $target, mixed $handle): void
    {
        if (!\in_array($target->kind, [TargetKind::Descriptor, TargetKind::Stream], true)) {
            return;
        }
        [$blocking, $warning] = NativeCall::attempt(static fn() => stream_set_blocking($handle, true));
        if (!$blocking) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot enable blocking writes', $warning ?? 'unknown error');
        }
    }

    /** @param resource $handle */
    private static function verifyOpenedIdentity(ResolvedTarget $target, string $path, mixed $handle): void
    {
        clearstatcache(true, $path);
        [$named] = NativeCall::attempt(static fn() => lstat($path));
        $opened = fstat($handle);
        if ($named === false || $opened === false || $target->identity === null
            || !FileIdentity::fromStat($opened)->sameAs($target->identity)
            || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))
            || !self::directoriesStillMatch($target)) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'target identity changed while opening');
        }
    }

    private static function directoriesStillMatch(ResolvedTarget $target): bool
    {
        foreach ($target->directories as $directory) {
            clearstatcache(true, $directory['path']);
            [$now] = NativeCall::attempt(static fn() => lstat($directory['path']));
            if ($now === false || !$directory['identity']->sameAs(FileIdentity::fromStat($now))) {
                return false;
            }
        }

        return true;
    }

    private static function linkFailure(string $path, ?string $linkWarning): never
    {
        clearstatcache(true, $path);
        [$appeared] = NativeCall::attempt(static fn() => lstat($path));
        if ($appeared !== false) {
            throw new FileTargetFailure(FileTargetFailureKind::Appeared, $path, 'target appeared before exclusive creation');
        }

        throw new FileTargetFailure(FileTargetFailureKind::NoHardLinks, $path, 'filesystem cannot create a hard link', $linkWarning ?? 'unknown error');
    }
}
