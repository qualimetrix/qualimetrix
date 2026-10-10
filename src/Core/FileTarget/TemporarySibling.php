<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

final class TemporarySibling
{
    /** @var resource|null */
    private mixed $handle;

    /** @param resource $handle */
    private function __construct(private AbsolutePath $path, mixed $handle, private readonly int $ownerPid)
    {
        $this->handle = $handle;
    }

    public static function create(AbsolutePath $directory, int $mode = 0600): self
    {
        $ownerPid = getmypid();
        if ($ownerPid === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $directory->value(), 'cannot determine the temporary owner process');
        }
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                $name = '.qmx-' . bin2hex(random_bytes(6));
            } catch (Throwable $error) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $directory->value(), 'cannot name a temporary file', $error->getMessage());
            }
            $path = AbsolutePath::fromString(rtrim($directory->value(), '/') . '/' . $name);
            $previousMask = umask();
            try {
                umask($previousMask | (0777 & ~$mode));
                [$handle, $openWarning] = NativeCall::attempt(static fn() => fopen($path->value(), 'x+e'));
            } finally {
                umask($previousMask);
            }
            if ($handle !== false) {
                return self::verifyCreated($path, $handle, $ownerPid);
            }
            clearstatcache(true, $path->value());
            [$named] = NativeCall::attempt(static fn() => lstat($path->value()));
            if ($named === false) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $path->value(), 'cannot create a temporary file', $openWarning ?? 'unknown error');
            }
        }

        throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $directory->value(), 'temporary names collided repeatedly');
    }

    /** @param resource $handle */
    private static function verifyCreated(AbsolutePath $path, mixed $handle, int $ownerPid): self
    {
        $temporary = new self($path, $handle, $ownerPid);
        $opened = fstat($handle);
        clearstatcache(true, $path->value());
        [$named] = NativeCall::attempt(static fn() => lstat($path->value()));
        if ($opened === false || $named === false || !FileIdentity::fromStat($opened)->sameAs(FileIdentity::fromStat($named))) {
            try {
                $temporary->cleanupLinkedReferent($path->value());
            } finally {
                $temporary->discard();
            }
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $path->value(), 'temporary file identity changed while opening');
        }

        return $temporary;
    }

    public function path(): AbsolutePath
    {
        return $this->path;
    }

    /** @return resource */
    public function handle(): mixed
    {
        if ($this->handle === null) {
            throw new LogicException('Temporary file handle has been released');
        }

        return $this->handle;
    }

    /** @return resource */
    public function takeHandle(): mixed
    {
        $handle = $this->handle();
        $this->handle = null;

        return $handle;
    }

    public function cleanupLinkedReferent(string $linkedName): void
    {
        $opened = fstat($this->handle());
        if ($opened === false) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $linkedName, 'cannot inspect linked file for cleanup');
        }
        clearstatcache(true, $linkedName);
        [$link] = NativeCall::attempt(static fn() => readlink($linkedName));
        if ($link === false) {
            return;
        }
        $referent = str_starts_with($link, '/') ? $link : \dirname($linkedName) . '/' . $link;
        clearstatcache(true, $referent);
        [$named] = NativeCall::attempt(static fn() => lstat($referent));
        if ($named !== false && FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened))) {
            [$removed, $warning] = NativeCall::attempt(static fn() => unlink($referent));
            if (!$removed) {
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $linkedName, 'cannot remove a referent created through a symbolic link', $warning ?? 'unknown error');
            }
        }
    }

    public function discard(): void
    {
        if ($this->handle === null) {
            return;
        }
        if ($this->ownerPid !== getmypid()) {
            fclose($this->handle);
            $this->handle = null;

            return;
        }

        $opened = fstat($this->handle);
        clearstatcache(true, $this->path->value());
        [$named] = NativeCall::attempt(fn() => lstat($this->path->value()));
        if ($named !== false && $opened !== false && FileIdentity::fromStat($opened)->sameAs(FileIdentity::fromStat($named))) {
            [$removed, $warning] = NativeCall::attempt(fn() => unlink($this->path->value()));
            if (!$removed) {
                fclose($this->handle);
                $this->handle = null;
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->path->value(), 'cannot remove the temporary file', $warning ?? 'unknown error');
            }
        }
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            $this->discard();
        }
    }
}
