<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;

final class HeldTarget
{
    /** @var resource|null */
    private mixed $handle;

    /** @param resource $handle */
    private function __construct(
        private readonly ResolvedTarget $target,
        mixed $handle,
        private bool $created,
        private readonly int $ownerPid,
    ) {
        $this->handle = $handle;
    }

    public static function claim(ResolvedTarget $judged): self
    {
        $ownerPid = getmypid();
        if ($ownerPid === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $judged->spelling, 'cannot determine the target owner process');
        }
        [$target, $handle, $created] = TargetClaim::open($judged);

        return new self($target, $handle, $created, $ownerPid);
    }

    public function markAttached(): void
    {
        $this->created = false;
    }

    /** @param resource $stream */
    public static function writeToStream(mixed $stream, string $bytes, string $spelling): void
    {
        [$blocking, $warning] = NativeCall::attempt(static fn() => stream_set_blocking($stream, true));
        if (!$blocking) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'cannot enable blocking writes', $warning ?? 'unknown error');
        }

        self::writeAllTo($stream, $bytes, $spelling);
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
        if ($this->ownerPid !== getmypid()) {
            fclose($this->handle);
            $this->handle = null;

            return;
        }
        try {
            if ($this->created) {
                $path = $this->target->path?->value() ?? throw new LogicException('Created target has no path');
                clearstatcache(true, $path);
                [$named] = NativeCall::attempt(static fn() => lstat($path));
                if ($named !== false && FileIdentity::fromStat($named)->sameAs($this->identity())) {
                    [$removed, $warning] = NativeCall::attempt(static fn() => unlink($path));
                    if (!$removed) {
                        throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $path, 'cannot remove unwritten target', $warning ?? 'unknown error');
                    }
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
        self::writeAllTo($this->openedHandle(), $bytes, $this->target->spelling);
    }

    /** @param resource $handle */
    private static function writeAllTo(mixed $handle, string $bytes, string $spelling): void
    {
        $total = \strlen($bytes);
        $offset = 0;
        while ($offset < $total) {
            [$written, $warning] = NativeCall::attempt(static fn() => fwrite($handle, substr($bytes, $offset)));
            if ($written === false || $written === 0) {
                throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $spelling, \sprintf('wrote %d of %d bytes', $offset, $total), $warning ?? 'write returned no bytes');
            }
            $offset += $written;
        }
        [$flushed, $warning] = NativeCall::attempt(static fn() => fflush($handle));
        if (!$flushed) {
            throw new FileTargetFailure(FileTargetFailureKind::PartialWrite, $spelling, \sprintf('wrote %d of %d bytes but flush failed', $offset, $total), $warning ?? 'unknown error');
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
}
