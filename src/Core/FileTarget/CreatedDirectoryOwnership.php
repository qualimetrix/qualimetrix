<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final class CreatedDirectoryOwnership
{
    /** @var array<string, array{stat: array<string|int, int>, uid: int}> */
    private static array $directories = [];
    private static int|false|null $ownerPid = null;

    public static function remember(string $directory): void
    {
        self::resetAfterFork();
        $canonical = realpath($directory);
        if ($canonical === false) {
            return;
        }
        $directory = $canonical;
        clearstatcache(true, $directory);
        [$stat] = NativeCall::attempt(static fn() => lstat($directory));
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0022) !== 0) {
            return;
        }
        $uid = ProcessOwner::effectiveUid($directory);
        if ($stat['uid'] === $uid) {
            self::$directories[$directory] = ['stat' => $stat, 'uid' => $uid];
        }
    }

    /** @param array<string|int, int> $current */
    public static function ownerOf(string $directory, array $current): ?int
    {
        self::resetAfterFork();
        $created = self::$directories[$directory] ?? null;
        if ($created === null) {
            return null;
        }

        $effectiveUid = \function_exists('posix_geteuid') ? posix_geteuid() : ProcessOwner::effectiveUid($directory);
        if ($effectiveUid !== $created['uid'] || !self::sameDirectory($created['stat'], $current)) {
            unset(self::$directories[$directory]);

            return null;
        }

        return $created['uid'];
    }

    /**
     * @param array<string|int, int> $before
     * @param array<string|int, int> $after
     */
    private static function sameDirectory(array $before, array $after): bool
    {
        return FileIdentity::fromStat($before)->sameAs(FileIdentity::fromStat($after))
            && $before['mode'] === $after['mode']
            && $before['uid'] === $after['uid']
            && $before['gid'] === $after['gid'];
    }

    private static function resetAfterFork(): void
    {
        $pid = getmypid();
        if (self::$ownerPid !== $pid) {
            self::$directories = [];
            self::$ownerPid = $pid;
        }
    }
}
