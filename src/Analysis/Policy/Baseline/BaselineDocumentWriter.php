<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use RuntimeException;

/**
 * Replaces a baseline file atomically, under a real compare-and-swap guard
 * (ADR 0017).
 *
 * **Atomicity** is temp file plus rename.
 *
 * **Concurrency** is a lock held across *both* the content check and the
 * rename. Re-reading the file before writing and hoping nothing happens next
 * is a TOCTOU window, not a guard — the whole value of the check is that
 * nothing can slip between it and the replacement. The caller passes what it
 * read; if the file no longer hashes to it, someone else wrote in the
 * meantime and this write is refused rather than silently discarding their
 * work.
 *
 * **The lock is a sibling file**, `<baseline>.lock`, and it is not removed.
 * Removing a lock file is the classic way to break locking: a process
 * waiting on the inode acquires a lock on a file that no longer has a name,
 * while the next writer creates a fresh one and locks that instead — two
 * writers, two locks, one target. Locking the target itself fails for the
 * same reason in a different disguise, since `rename()` replaces the inode
 * out from under the holder. A stable, never-unlinked sibling is the only
 * variant of the three that is actually exclusive; it costs one empty file
 * next to the baseline, which is worth adding to `.gitignore`.
 *
 * The guard is owned here rather than by {@see BaselineWriter} because it is
 * a fact about the *file*, not about the object being written: the channel
 * carry rewrites the document as raw text and must not be able to introduce
 * a race the other `baseline:*` commands do not allow.
 */
final readonly class BaselineDocumentWriter
{
    /** How long acquisition of the sibling lock is retried before giving up. */
    public const float DEFAULT_LOCK_TIMEOUT_SECONDS = 10.0;

    /** Pause between attempts while waiting for the lock. */
    private const int LOCK_RETRY_INTERVAL_MICROSECONDS = 20_000;

    /**
     * @param float $lockTimeoutSeconds how long to wait for another writer to finish before
     *                                  reporting the contention; tests shorten it, nothing
     *                                  else needs to
     */
    public function __construct(
        private float $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS,
    ) {}

    /**
     * Replaces a file whose previous contents hashed to `$expectedHash`.
     *
     * A `null` expectation means nothing was read, so there is nothing to
     * conflict with and only the atomicity guarantee applies.
     *
     * @throws BaselineConflictException if the target changed or vanished since it was read
     * @throws RuntimeException if the write fails
     */
    public function replace(string $path, string $contents, ?string $expectedHash): void
    {
        $this->guarded($path, $contents, function () use ($path, $expectedHash): void {
            if ($expectedHash !== null) {
                self::assertUnchanged($path, $expectedHash);
            }
        });
    }

    /**
     * Writes a file the caller read as absent, and refuses if it has since
     * appeared.
     *
     * @throws BaselineConflictException if the target exists after all
     * @throws RuntimeException if the write fails
     */
    public function create(string $path, string $contents): void
    {
        $this->guarded($path, $contents, static function () use ($path): void {
            if (file_exists($path) || is_link($path)) {
                throw new BaselineConflictException(\sprintf(
                    'Baseline file %s appeared since it was read as absent; refusing to overwrite. '
                    . 'Re-run the command to pick up the current file.',
                    $path,
                ));
            }
        });
    }

    /**
     * Holds the lock across the check and the replacement, which is the only
     * arrangement in which the check means anything.
     *
     * @param callable(): void $assertExpectation
     */
    private function guarded(string $path, string $contents, callable $assertExpectation): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        $lock = $this->acquireLock($path);

        try {
            $assertExpectation();
            self::replaceAtomically($path, $contents);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Waits for the sibling lock, but not forever.
     *
     * A blocking `flock(LOCK_EX)` is correct and, in the failure it actually
     * meets, useless: a crashed writer releases through the OS, while a
     * *hung* one — or a filesystem where `flock` misbehaves — leaves the next
     * `qmx` invocation stopped with no output at all. In CI that reads as a
     * job timeout with an empty log rather than as a baseline problem. A
     * bounded wait turns the same situation into a sentence naming the lock
     * file, which a user can act on.
     *
     * @return resource
     */
    private function acquireLock(string $path)
    {
        $lockPath = $path . '.lock';
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            throw new RuntimeException("Failed to open baseline lock file: {$lockPath}");
        }

        $deadline = microtime(true) + $this->lockTimeoutSeconds;

        while (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new RuntimeException(\sprintf(
                    'Timed out after %.1fs waiting for the baseline lock %s — another process is '
                    . 'writing this baseline, or one exited while holding the lock.',
                    $this->lockTimeoutSeconds,
                    $lockPath,
                ));
            }

            usleep(self::LOCK_RETRY_INTERVAL_MICROSECONDS);
        }

        return $handle;
    }

    /**
     * The two ways the target can have moved out from under a read are
     * separate facts with separate remedies, so they get separate messages:
     * a file someone else rewrote is picked up by re-running, while a file
     * that is simply gone makes the same advice fail one step earlier, in
     * the loader.
     *
     * @throws BaselineConflictException
     */
    private static function assertUnchanged(string $path, string $expectedHash): void
    {
        if (is_link($path)) {
            throw new BaselineConflictException(\sprintf(
                'Baseline file %s is a symbolic link; refusing to replace a different filesystem entry '
                . 'than the one whose contents were read.',
                $path,
            ));
        }

        if (!is_file($path)) {
            throw new BaselineConflictException(\sprintf(
                'Baseline file %s no longer exists; refusing to recreate it from a stale reading. '
                . 'Regenerate the baseline if its removal was intended.',
                $path,
            ));
        }

        if (hash_file('sha256', $path) !== $expectedHash) {
            throw new BaselineConflictException(\sprintf(
                'Baseline file %s changed since it was read; refusing to overwrite. '
                . 'Re-run the command to pick up the current file.',
                $path,
            ));
        }
    }

    private static function replaceAtomically(string $path, string $json): void
    {
        $tempPath = $path . '.tmp.' . getmypid();

        try {
            // Both calls warn on failure and both failures become exceptions
            // naming the same paths, so the native warning adds nothing but
            // noise on top of a message the caller already gets.
            //
            // Compared against the length rather than against `false`: a short
            // write — a full disk, an exceeded quota, an I/O error partway —
            // returns the count it managed, and the `rename()` below would
            // then atomically put a truncated document in place of a sound
            // baseline. The temp file is removed by the `finally` either way,
            // so the target is left exactly as it was.
            if (@file_put_contents($tempPath, $json) !== \strlen($json)) {
                throw new RuntimeException("Failed to write baseline to: {$tempPath}");
            }

            if (!@rename($tempPath, $path)) {
                throw new RuntimeException("Failed to move baseline from {$tempPath} to {$path}");
            }
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
