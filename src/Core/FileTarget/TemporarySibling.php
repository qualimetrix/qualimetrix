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
    private function __construct(private AbsolutePath $path, mixed $handle)
    {
        $this->handle = $handle;
    }

    public static function create(AbsolutePath $directory): self
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                $name = '.qmx-' . bin2hex(random_bytes(6));
            } catch (Throwable $error) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $directory->value(), 'cannot name a temporary file', $error->getMessage());
            }
            $path = AbsolutePath::fromString(rtrim($directory->value(), '/') . '/' . $name);
            $handle = @fopen($path->value(), 'x+e');
            if ($handle !== false) {
                $temporary = new self($path, $handle);
                $opened = fstat($handle);
                clearstatcache(true, $path->value());
                $named = @lstat($path->value());
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
            clearstatcache(true, $path->value());
            if (@lstat($path->value()) === false) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $path->value(), 'cannot create a temporary file', error_get_last()['message'] ?? 'unknown error');
            }
        }

        throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $directory->value(), 'temporary names collided repeatedly');
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
        $link = @readlink($linkedName);
        if ($link === false) {
            return;
        }
        $referent = str_starts_with($link, '/') ? $link : \dirname($linkedName) . '/' . $link;
        clearstatcache(true, $referent);
        $named = @lstat($referent);
        if ($named !== false && FileIdentity::fromStat($named)->sameAs(FileIdentity::fromStat($opened)) && !@unlink($referent)) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $linkedName, 'cannot remove a referent created through a symbolic link', error_get_last()['message'] ?? 'unknown error');
        }
    }

    public function discard(): void
    {
        if ($this->handle === null) {
            return;
        }

        $opened = fstat($this->handle);
        clearstatcache(true, $this->path->value());
        $named = @lstat($this->path->value());
        if ($named !== false && $opened !== false && FileIdentity::fromStat($opened)->sameAs(FileIdentity::fromStat($named))) {
            if (!@unlink($this->path->value())) {
                fclose($this->handle);
                $this->handle = null;
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->path->value(), 'cannot remove the temporary file', error_get_last()['message'] ?? 'unknown error');
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
