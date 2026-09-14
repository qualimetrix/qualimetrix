<?php

declare(strict_types=1);

namespace QmxFindingGate;

use Throwable;

/**
 * Everything this process borrowed and has to give back: its scratch
 * directories, and the reference worktree registered in the developer's
 * repository.
 *
 * Not the controls harness's `QmxFindingGateControls\Scratch`, which is a
 * hardlink clone of a working tree. This one holds no content of its own; it
 * holds the releases.
 *
 * Acquiring is what arms the release, rather than the entry point remembering
 * to: {@see directory()} arms interruption handling before it creates anything,
 * so a run cannot come to own something it can leak without owning what frees
 * it. A worker child inherits both without being told. This is the argument
 * {@see ReferenceTree} already makes for its own vocabulary check — a step the
 * caller has to remember is a step one refactoring removes.
 */
final class Scratch
{
    /** @var array<int, callable(): void> registration order, released in reverse */
    private static array $held = [];

    private static int $next = 0;

    private static bool $backstopInstalled = false;

    /**
     * A scratch directory that is released on every exit path.
     *
     * Arm, register, then create — in that order. Armed first, because a signal
     * arriving before the handlers exist is the default disposition and takes
     * the directory with it; registered before the `mkdir`, because a signal
     * between creating and registering would leave a directory nobody holds.
     * Releasing a path that was never created is a no-op, so registering early
     * costs nothing.
     */
    public static function directory(string $prefix): string
    {
        Interruption::arm();

        $path = rtrim(sys_get_temp_dir(), '/') . '/' . $prefix . bin2hex(random_bytes(6));
        self::hold(static fn() => Fs::removeRecursively($path));

        if (!@mkdir($path, 0o700, true)) {
            throw new GateError(\sprintf('Cannot create temporary directory %s.', $path));
        }

        // Handed out resolved, because these paths are compared against paths
        // git prints, and git prints them resolved. On macOS `sys_get_temp_dir()`
        // is `/var/tmp/` — a symlink to `/private/var/tmp`, and with a trailing
        // slash — so a checkout registered from here is listed by
        // `git worktree list` under a spelling this process never produced.
        // Measured 2026-09-14: the release verification compared the two
        // spellings literally and could therefore never match, which made it
        // inert exactly where it was supposed to be loud. Resolved after the
        // `mkdir`, since `realpath()` answers for a path that exists.
        $resolved = realpath($path);

        return $resolved === false ? $path : $resolved;
    }

    /**
     * Registers a release that is not a directory removal, and returns the
     * canceller for it.
     *
     * The canceller is what a caller that has released the thing itself uses to
     * stop holding it, so that the shutdown backstop does not run a removal a
     * second time against a path some later run may own.
     *
     * @param callable(): void $release
     *
     * @return callable(): void
     */
    public static function hold(callable $release): callable
    {
        self::installBackstop();

        $key = self::$next++;
        self::$held[$key] = $release;

        return static function () use ($key): void {
            unset(self::$held[$key]);
        };
    }

    /**
     * Releases everything still held, most recent first, and never throws.
     *
     * Reverse order because the holdings nest: the reference worktree has to be
     * deregistered before the directory it lives in is removed.
     *
     * A failed release is reported to STDERR rather than raised. This runs from
     * the shutdown path, where an exception would replace the failure being
     * reported with one about tidying up — the rule `Shell::descendants()`
     * already follows. Reported and not swallowed, because the one failure that
     * matters here is a worktree that stayed registered, and silence about that
     * is what made this class necessary.
     */
    public static function releaseAll(): void
    {
        Interruption::stopRaising();

        foreach (array_reverse(self::$held, true) as $key => $release) {
            unset(self::$held[$key]);

            try {
                $release();
            } catch (Throwable $error) {
                fwrite(\STDERR, 'finding-gate: cannot release scratch state: ' . $error->getMessage() . "\n");
            }
        }
    }

    /** The one path no flag reaches: a PHP fatal runs shutdown functions and nothing else. */
    private static function installBackstop(): void
    {
        if (self::$backstopInstalled) {
            return;
        }

        self::$backstopInstalled = true;
        register_shutdown_function(self::releaseAll(...));
    }
}
