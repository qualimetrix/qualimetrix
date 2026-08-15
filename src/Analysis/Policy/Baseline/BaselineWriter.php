<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use RuntimeException;
use stdClass;

/**
 * Writes a baseline file atomically, under a real compare-and-swap guard
 * (ADR 0017).
 *
 * **Atomicity** is temp file plus rename, as before.
 *
 * **Concurrency** is a lock held across *both* the content check and the
 * rename. Re-reading the file before writing and hoping nothing happens next
 * is a TOCTOU window, not a guard — the whole value of the check is that
 * nothing can slip between it and the replacement. When the baseline being
 * written was loaded from the same file, {@see Baseline::$sourceContentHash}
 * carries what was read; if the file no longer hashes to it, someone else
 * wrote in the meantime and this write is refused rather than silently
 * discarding their work.
 *
 * The hash is a property of the guard, never of the file: nothing written
 * here is described by it, and ADR 0017 lists no such field.
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
 */
final readonly class BaselineWriter
{
    /** How long acquisition of the sibling lock is retried before giving up. */
    private const float DEFAULT_LOCK_TIMEOUT_SECONDS = 10.0;

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
     * Writes the baseline and returns the SHA-256 of the bytes written —
     * the token a caller passes on if it goes on to modify and write again,
     * via {@see Baseline::withSourceContentHash()}.
     *
     * @throws BaselineConflictException if the target changed or vanished since it was read
     * @throws RuntimeException if the write fails
     */
    public function write(Baseline $baseline, string $path, AbsolutePath $projectRoot): string
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        $json = $this->encode($this->serializeBaseline($baseline, $projectRoot));

        $lock = $this->acquireLock($path);

        try {
            $this->assertUnchanged($baseline, $path);
            $this->replaceAtomically($path, $json);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return hash('sha256', $json);
    }

    /**
     * Encodes with the float representation pinned at this call, not
     * inherited from the environment.
     *
     * Six-decimal normalisation alone does not make the bytes independent of
     * the reader's ini, and assuming it did would have been a bet rather
     * than a guarantee: `0.1` has no exact binary form, so at
     * `serialize_precision=17` PHP writes `0.10000000000000001` and at `15`
     * it writes `0.1` — the same value, two files. `-1` selects the shortest
     * representation that round-trips to the identical double, which is both
     * stable across every ambient setting and lossless. The setting is
     * process-global, so it is restored immediately.
     *
     * `JSON_PRESERVE_ZERO_FRACTION` is deliberately not passed: a normalised
     * `40.0` is written as `40` and reloads as an `int`, which is harmless
     * for a numeric comparison and stable from the first write.
     *
     * A pin that did not take is treated as a failed write rather than as a
     * quietly degraded one: `ini_set` answers with the previous value on
     * success and `false` on failure, so the two are distinguishable — and
     * the guarantee above is worth nothing if its failure looks exactly like
     * its success. Using that return value also means the restore puts back
     * precisely what was there, instead of guessing a default for a setting
     * that was never successfully read.
     *
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $previous = ini_set('serialize_precision', '-1');

        if ($previous === false) {
            throw new RuntimeException(
                'Failed to pin serialize_precision for the baseline write; the file would not be '
                . 'reproducible across environments.',
            );
        }

        try {
            return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        } finally {
            ini_set('serialize_precision', $previous);
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
    private function assertUnchanged(Baseline $baseline, string $path): void
    {
        if ($baseline->expectsSourceAbsence) {
            if (file_exists($path) || is_link($path)) {
                throw new BaselineConflictException(\sprintf(
                    'Baseline file %s appeared since it was read as absent; refusing to overwrite. '
                    . 'Re-run the command to pick up the current file.',
                    $path,
                ));
            }

            return;
        }

        if ($baseline->sourceContentHash === null) {
            return;
        }

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

        if (hash_file('sha256', $path) !== $baseline->sourceContentHash) {
            throw new BaselineConflictException(\sprintf(
                'Baseline file %s changed since it was read; refusing to overwrite. '
                . 'Re-run the command to pick up the current file.',
                $path,
            ));
        }
    }

    private function replaceAtomically(string $path, string $json): void
    {
        $tempPath = $path . '.tmp.' . getmypid();

        try {
            // Both calls warn on failure and both failures become exceptions
            // naming the same paths, so the native warning adds nothing but
            // noise on top of a message the caller already gets.
            if (@file_put_contents($tempPath, $json) === false) {
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

    /**
     * @return array<string, mixed>
     */
    private function serializeBaseline(Baseline $baseline, AbsolutePath $projectRoot): array
    {
        $entries = $this->serializeEntries($baseline, $projectRoot);

        return [
            'version' => Baseline::VERSION,
            'generated' => $baseline->generated->format('c'),
            'scope' => $baseline->scope,
            'entries' => $entries === [] ? new stdClass() : $entries,
        ];
    }

    /**
     * Groups entries under their subject keys in a fixed order.
     *
     * **Every entry read is an entry written.** Entries are accumulated as a
     * list and never as a map: a map key that two entries can share resolves
     * the clash by overwriting, and an entry that leaves the file without
     * anybody deciding it should is removal by inference — the one thing
     * {@see InertBaselineEntry} exists to prevent. Two of those keys were
     * genuinely shared: every entry of a duplicated identity carries the same
     * selector by construction, and so do two byte-identical unreadable
     * lines.
     *
     * **Order does not depend on whether an entry happens to be applicable.**
     * All entries under a symbol sort by channel and then by edge, whatever
     * their state, so the file a command writes does not depend on which
     * configuration produced it. Applicability itself is not a stable fact
     * about an entry — a different `--preset`, a different `--config`, or a
     * run with `computed_metrics:` absent can each change whether a
     * `computed.*` entry resolves as applicable or inert from one invocation
     * to the next — so a valid-block-then-inert-block layout would move
     * those lines whenever that changed. Only an entry whose channel could
     * not be read at all has nothing to sort on; those follow, ordered by
     * selector. Ties keep their input order, which a stable sort guarantees.
     *
     * Inert entries are written back exactly as they were read: `cleanup`
     * never removes an entry on its own, and rewriting a line the loader
     * could not understand would be the same inference in a different place.
     *
     * @throws InvalidArgumentException when two entries collapse onto one identity after
     *                                  their subject keys are relativized
     *
     * @return array<string, list<mixed>>
     */
    private function serializeEntries(Baseline $baseline, AbsolutePath $projectRoot): array
    {
        /** @var array<string, list<array{sort: string, payload: mixed}>> $grouped */
        $grouped = [];
        /** @var array<string, array<string, true>> $seen */
        $seen = [];

        foreach ($baseline->entries as $entry) {
            $key = $this->portableKey($entry->identity->subjectKey, $projectRoot);
            $sort = self::orderingKey(
                $entry->identity->channel->toKey(),
                $entry->identity->occurrenceKey,
                $entry->identity->edge?->key(),
            );

            if (isset($seen[$key][$sort])) {
                throw new InvalidArgumentException(\sprintf(
                    'Two baseline entries collapse onto the identity %s once their subject keys are '
                    . 'made project-relative; refusing to write a file that would keep only one of them.',
                    $entry->identity->describe(),
                ));
            }

            $seen[$key][$sort] = true;
            $grouped[$key][] = ['sort' => $sort, 'payload' => $entry->toArray()];
        }

        foreach ($baseline->inertEntries as $entry) {
            $key = $this->portableKey($entry->subjectKey, $projectRoot);
            $grouped[$key][] = ['sort' => self::inertOrderingKey($entry), 'payload' => $entry->raw];
        }

        $serialized = [];
        foreach ($grouped as $key => $items) {
            usort($items, static fn(array $a, array $b): int => strcmp($a['sort'], $b['sort']));

            $payloads = [];
            foreach ($items as $item) {
                $payloads[] = $item['payload'];
            }

            $serialized[$key] = $payloads;
        }

        ksort($serialized, \SORT_STRING);

        return $serialized;
    }

    /**
     * Where an entry sorts among its siblings under one subject key. The
     * leading digit keeps entries with a readable channel ahead of the ones
     * that have nothing to sort on, without either group depending on the
     * other's contents.
     */
    private static function orderingKey(string $channelKey, ?string $occurrenceKey, ?string $edgeKey): string
    {
        return '0' . $channelKey . "\x1F" . ($occurrenceKey ?? '') . "\x1F" . ($edgeKey ?? '');
    }

    private static function inertOrderingKey(InertBaselineEntry $entry): string
    {
        if ($entry->identity !== null) {
            return self::orderingKey(
                $entry->identity->channel->toKey(),
                $entry->identity->occurrenceKey,
                $entry->identity->edge?->key(),
            );
        }

        if ($entry->channelKey !== null) {
            return self::orderingKey($entry->channelKey, null, null);
        }

        return '1' . $entry->selector->value;
    }

    /**
     * Converts absolute `file:` canonical paths to relative for portability.
     *
     * Only affects `file:` keys — `class:`, `callable:`, `ns:` keys are
     * FQN-based and already portable. Out-of-tree absolute paths are
     * preserved verbatim so external baselines stay round-trippable.
     * Malformed `file:` payloads (empty, lexically escaping segments)
     * propagate as VO construction exceptions: the writer treats them as
     * in-memory corruption, not as tolerated input.
     *
     * **This is the one place where two identities can become one.** The
     * duplicate guard in {@see Baseline} runs on the raw subject key, so
     * `file:<root>/src/Foo.php` and `file:src/Foo.php` are two legal
     * identities in memory that name one key here. {@see serializeEntries()}
     * therefore refuses such a pair rather than resolving it: silently
     * dropping one of two accepted ceilings is the unsafe direction, and
     * which one survived would depend on assembly order. Normalizing the key
     * on the way into the object instead would need the project root at every
     * construction site, including {@see BaselineLoader}, which has no reason
     * to know it; that plumbing belongs with P3's measured-set seam, not with
     * the file format.
     */
    private function portableKey(string $canonical, AbsolutePath $projectRoot): string
    {
        if (!str_starts_with($canonical, 'file:')) {
            return $canonical;
        }

        $filePath = substr($canonical, 5);

        if ($filePath === '') {
            return $canonical;
        }

        $relative = PathFactory::tryProjectRelative($filePath, $projectRoot);

        return $relative !== null ? 'file:' . $relative->value() : $canonical;
    }
}
