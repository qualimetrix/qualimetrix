<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

final class HeldLock
{
    /** @var resource|null */
    private mixed $handle;

    /** @param resource $handle */
    private function __construct(mixed $handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(ResolvedTarget $lockFile, float $timeoutSeconds): self
    {
        if (!$lockFile->sameAs(TargetPath::resolve($lockFile->spelling, $lockFile->membership()))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $lockFile->spelling, 'lock target changed before acquisition');
        }

        $deadline = hrtime(true) / 1e9 + $timeoutSeconds;
        do {
            $now = TargetPath::resolve($lockFile->spelling, $lockFile->membership());
            $path = self::lockPath($lockFile, $now);
            if ($now->kind === TargetKind::Absent) {
                self::createLockName($lockFile, $path);
            }

            $handle = self::openLock($lockFile, $path);
            try {
                if (self::lockMatchesName($handle, $path)) {
                    return new self($handle);
                }
            } catch (Throwable $error) {
                fclose($handle);
                throw $error;
            }
            fclose($handle);
            usleep(10_000);
        } while (hrtime(true) / 1e9 < $deadline);

        throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $lockFile->spelling, 'timed out waiting for lock');
    }

    private static function lockPath(ResolvedTarget $judged, ResolvedTarget $now): string
    {
        if ($now->kind !== TargetKind::Regular && $now->kind !== TargetKind::Absent) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $judged->spelling, 'lock target is not a regular file');
        }

        return $now->path?->value() ?? throw new LogicException('Lock path is missing');
    }

    private static function createLockName(ResolvedTarget $lockFile, string $path): void
    {
        $temporary = TemporarySibling::create(AbsolutePath::fromString(\dirname($path)));
        try {
            [$linked, $linkWarning] = NativeCall::attempt(static fn() => link($temporary->path()->value(), $path));
            if (!$linked) {
                clearstatcache(true, $path);
                [$appeared] = NativeCall::attempt(static fn() => lstat($path));
                if ($appeared === false) {
                    throw new FileTargetFailure(FileTargetFailureKind::NoHardLinks, $lockFile->spelling, 'filesystem cannot create a lock hard link', $linkWarning ?? 'unknown error');
                }

                return;
            }

            clearstatcache(true, $path);
            [$named] = NativeCall::attempt(static fn() => lstat($path));
            $opened = fstat($temporary->handle());
            if ($named === false || $opened === false || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
                $temporary->cleanupLinkedReferent($path);
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $lockFile->spelling, 'new lock identity changed after linking');
            }
        } finally {
            $temporary->discard();
        }
    }

    /** @return resource */
    private static function openLock(ResolvedTarget $lockFile, string $path): mixed
    {
        [$handle, $warning] = NativeCall::attempt(static fn() => fopen($path, 're'));
        if ($handle === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $lockFile->spelling, 'cannot open lock file', $warning ?? 'unknown error');
        }

        return $handle;
    }

    /** @param resource $handle */
    private static function lockMatchesName(mixed $handle, string $path): bool
    {
        [$locked] = NativeCall::attempt(static fn() => flock($handle, \LOCK_EX | \LOCK_NB));
        if (!$locked) {
            return false;
        }

        clearstatcache(true, $path);
        [$named] = NativeCall::attempt(static fn() => lstat($path));
        $opened = fstat($handle);
        if ($named !== false && $opened !== false && FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
            return true;
        }
        flock($handle, \LOCK_UN);

        return false;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, \LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
