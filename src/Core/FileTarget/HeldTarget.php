<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

final class HeldTarget
{
    /** @var resource|null */
    private mixed $handle;

    /** @param resource $handle */
    private function __construct(
        private readonly ResolvedTarget $target,
        mixed $handle,
        private bool $created,
    ) {
        $this->handle = $handle;
    }

    public static function claim(ResolvedTarget $judged, ClaimMode $mode): self
    {
        $now = TargetPath::resolve($judged->spelling);
        if (!$judged->sameAs($now)) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $judged->spelling, 'target changed before it could be claimed');
        }

        if ($now->kind === TargetKind::Absent) {
            $path = $now->path?->value() ?? throw new LogicException('Absent target has no path');
            $temporary = TemporarySibling::create(AbsolutePath::fromString(\dirname($path)));
            try {
                if (!@link($temporary->path()->value(), $path)) {
                    self::linkFailure($path);
                }
                clearstatcache(true, $path);
                $named = @lstat($path);
                $opened = fstat($temporary->handle());
                if ($named === false || $opened === false || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
                    $temporary->cleanupLinkedReferent($path);
                    throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $judged->spelling, 'new target identity changed after linking');
                }
                $temporaryPath = $temporary->path()->value();
                if (!@unlink($temporaryPath)) {
                    throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $judged->spelling, 'cannot remove temporary link', error_get_last()['message'] ?? 'unknown error');
                }
                $handle = $temporary->takeHandle();

                return new self($now, $handle, true);
            } finally {
                $temporary->discard();
            }
        }

        if ($now->kind === TargetKind::Stream && $now->streamExposed) {
            throw new FileTargetFailure(FileTargetFailureKind::ExposedStream, $judged->spelling, 'stream path can be changed by another user');
        }

        $path = $now->kind === TargetKind::Descriptor ? 'php://fd/' . $now->descriptor : $now->path?->value();
        $opening = $now->kind === TargetKind::Regular ? 'r+e' : 'we';
        $handle = @fopen($path ?? '', $opening);
        if ($handle === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $judged->spelling, 'cannot open target', error_get_last()['message'] ?? 'unknown error');
        }

        try {
            if ($now->kind !== TargetKind::Descriptor) {
                clearstatcache(true, $path);
                $named = @lstat($path);
                $opened = fstat($handle);
                if ($named === false || $opened === false || $now->identity === null
                    || !FileIdentity::fromStat($opened)->sameAs($now->identity)
                    || !FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))
                    || !self::directoriesStillMatch($now)) {
                    throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $judged->spelling, 'target identity changed while opening');
                }
            }

            return new self($now, $handle, false);
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }

    public function identity(): FileIdentity
    {
        $stat = $this->stat();

        return FileIdentity::fromStat($stat);
    }

    public function write(string $bytes): void
    {
        $handle = $this->openedHandle();
        if ($this->target->kind === TargetKind::Regular || $this->target->kind === TargetKind::Absent) {
            if (!flock($handle, \LOCK_EX)) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->target->spelling, 'cannot lock target for replacement');
            }
            try {
                if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
                    throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->target->spelling, 'cannot truncate target');
                }
                $this->writeAll($bytes);
            } finally {
                flock($handle, \LOCK_UN);
            }
        } else {
            $this->writeAll($bytes);
        }
        $this->created = false;
    }

    public function append(string $bytes): void
    {
        $handle = $this->openedHandle();
        if ($this->target->kind === TargetKind::Descriptor || $this->target->kind === TargetKind::Stream) {
            $this->writeAll($bytes);

            return;
        }
        if (!flock($handle, \LOCK_EX)) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->target->spelling, 'cannot lock target for append');
        }
        try {
            if (fseek($handle, 0, \SEEK_END) !== 0) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->target->spelling, 'cannot seek to target end');
            }
            $this->writeAll($bytes);
            $this->created = false;
        } finally {
            flock($handle, \LOCK_UN);
        }
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        try {
            if ($this->created) {
                $path = $this->target->path?->value() ?? throw new LogicException('Created target has no path');
                clearstatcache(true, $path);
                $named = @lstat($path);
                if ($named !== false && FileIdentity::fromStat($named)->sameAs($this->identity()) && !@unlink($path)) {
                    throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $path, 'cannot remove unwritten target', error_get_last()['message'] ?? 'unknown error');
                }
            }
        } finally {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            $this->release();
        }
    }

    /** @return array<string|int, int> */
    private function stat(): array
    {
        $stat = fstat($this->openedHandle());
        if ($stat === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->target->spelling, 'cannot inspect opened target');
        }

        return $stat;
    }

    private function writeAll(string $bytes): void
    {
        $handle = $this->openedHandle();
        $total = \strlen($bytes);
        $offset = 0;
        while ($offset < $total) {
            $written = @fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $this->target->spelling, \sprintf('wrote %d of %d bytes', $offset, $total), error_get_last()['message'] ?? 'write returned no bytes');
            }
            $offset += $written;
        }
        if (!@fflush($handle)) {
            throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $this->target->spelling, \sprintf('wrote %d of %d bytes but flush failed', $offset, $total), error_get_last()['message'] ?? 'unknown error');
        }
    }

    /** @return resource */
    private function openedHandle(): mixed
    {
        if ($this->handle === null) {
            throw new LogicException('Target handle has been released');
        }

        return $this->handle;
    }

    private static function directoriesStillMatch(ResolvedTarget $target): bool
    {
        foreach ($target->directories as $directory) {
            clearstatcache(true, $directory['path']);
            $now = @lstat($directory['path']);
            if ($now === false || !$directory['identity']->sameAs(FileIdentity::fromStat($now))) {
                return false;
            }
        }

        return true;
    }

    private static function linkFailure(string $path): never
    {
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            throw new FileTargetFailure(FileTargetFailureKind::Appeared, $path, 'target appeared before exclusive creation');
        }

        throw new FileTargetFailure(FileTargetFailureKind::NoHardLinks, $path, 'filesystem cannot create a hard link', error_get_last()['message'] ?? 'unknown error');
    }

}
