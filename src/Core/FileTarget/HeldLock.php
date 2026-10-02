<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;

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
        if (!$lockFile->sameAs(TargetPath::resolve($lockFile->spelling))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $lockFile->spelling, 'lock target changed before acquisition');
        }

        $deadline = hrtime(true) / 1e9 + $timeoutSeconds;
        do {
            $now = TargetPath::resolve($lockFile->spelling);
            if ($now->kind !== TargetKind::Regular && $now->kind !== TargetKind::Absent) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $lockFile->spelling, 'lock target is not a regular file');
            }

            $path = $now->path?->value() ?? throw new LogicException('Lock path is missing');
            if ($now->kind === TargetKind::Absent) {
                $temporary = TemporarySibling::create(AbsolutePath::fromString(\dirname($path)));
                try {
                    if (!@link($temporary->path()->value(), $path)) {
                        clearstatcache(true, $path);
                        if (@lstat($path) === false) {
                            throw new FileTargetFailure(FileTargetFailureKind::NoHardLinks, $lockFile->spelling, 'filesystem cannot create a lock hard link', error_get_last()['message'] ?? 'unknown error');
                        }
                    } else {
                        clearstatcache(true, $path);
                        $named = @lstat($path);
                        $opened = fstat($temporary->handle());
                        if ($named === false || $opened === false || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
                            $temporary->cleanupLinkedReferent($path);
                            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $lockFile->spelling, 'new lock identity changed after linking');
                        }
                    }
                } finally {
                    $temporary->discard();
                }
            }

            $handle = @fopen($path, 're');
            if ($handle === false) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $lockFile->spelling, 'cannot open lock file', error_get_last()['message'] ?? 'unknown error');
            }
            if (@flock($handle, \LOCK_EX | \LOCK_NB)) {
                clearstatcache(true, $path);
                $named = @lstat($path);
                $opened = fstat($handle);
                if ($named !== false && $opened !== false && FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
                    return new self($handle);
                }
                flock($handle, \LOCK_UN);
            }
            fclose($handle);
            usleep(10_000);
        } while (hrtime(true) / 1e9 < $deadline);

        throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $lockFile->spelling, 'timed out waiting for lock');
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
