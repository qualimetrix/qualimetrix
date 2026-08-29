<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use RuntimeException;

/**
 * A scratch candidate tree: a hardlink clone of the *working tree*, not of a
 * commit.
 *
 * Two reasons it cannot be a `git worktree`. The corpus and the gate are
 * uncommitted while Ш1 is being built, so a checkout of HEAD would not contain
 * the very input under test. And a control must plant its breakage in a tree
 * that is otherwise byte-identical to what the developer is looking at.
 *
 * What gets cloned is the tree's *content*, enumerated by git: tracked files
 * plus untracked files git does not ignore, at their working-tree bytes. What
 * gets left out is everything git ignores, which is where all the weight lives
 * — measured 2026-08-23 on this repository, a whole-directory clone hardlinked
 * 215k entries and took 69s to make and 19s to remove, of which 207k entries
 * were tool caches and build output (`.claude`, `.qmx-cache`, `coverage`,
 * `.phpstan.cache`, `.venv`, `benchmarks/vendor`). Content plus `vendor/` plus
 * `.git` is 8k entries and 10s. The `du` figure of 2.7G was never real disk
 * cost — hardlinks share their blocks, and `du` on the clone alone cannot see
 * that — but the inode churn was, in both directions.
 *
 * `vendor/` is git-ignored and cloned anyway, because the gate hardlinks the
 * candidate's `vendor/` into the reference tree; without it there is nothing to
 * run. It is also the only such exception, so a missing input shows up as a
 * loud gate failure rather than a quietly narrower comparison.
 *
 * `.git` is a real copy, not hardlinked: the gate creates and removes a
 * worktree inside the candidate's repository, and no control may reach into the
 * repository the developer is working in. At 21M/462 entries it is not worth
 * making cheaper.
 *
 * Never symlink `vendor/` — see the note on
 * scripts/finding-gate/ReferenceTree.php: Composer resolves `__DIR__` through
 * the link and autoloads the other tree's `src/`, which makes every comparison
 * vacuous while looking green.
 */
final class Scratch
{
    /** @var list<self> */
    private static array $live = [];

    private bool $removed = false;

    private function __construct(
        public readonly string $tree,
        private readonly string $directory,
    ) {}

    public static function cloneOf(string $repository): self
    {
        $directory = Shell::temporaryDirectory('finding-gate-controls-');
        $tree = $directory . '/tree';

        if (!@mkdir($tree)) {
            throw new RuntimeException(\sprintf('Cannot create %s.', $tree));
        }

        $scratch = new self($tree, $directory);
        self::$live[] = $scratch;

        self::linkContent($repository, $tree);

        if (!is_dir($repository . '/vendor')) {
            throw new RuntimeException(\sprintf('%s has no vendor/; run composer install first.', $repository));
        }

        Shell::mustRun(['cp', '-Rl', $repository . '/vendor', $tree . '/vendor'], $repository);
        Shell::mustRun(['cp', '-R', $repository . '/.git', $tree . '/.git'], $repository);

        // A copied `.git` brings the repository's worktree registrations with
        // it, including the reference checkout of an earlier gate run that was
        // killed before it could deregister. Git refuses to add a worktree
        // whose name is already registered, and the gate always names its
        // reference `tree` — so an unrelated stale entry would turn a control
        // into a crash. The scratch tree's own registrations mean nothing.
        Shell::removeRecursively($tree . '/.git/worktrees');

        return $scratch;
    }

    /**
     * Hardlinks every file git counts as this tree's content. A tracked path
     * the developer has deleted is skipped, because the clone must match what
     * the developer is looking at, not what the index remembers.
     */
    private static function linkContent(string $repository, string $tree): void
    {
        $listing = Shell::mustRun(
            ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            $repository,
        );

        $linked = 0;

        foreach (explode("\0", $listing) as $relative) {
            if ($relative === '') {
                continue;
            }

            $source = $repository . '/' . $relative;
            $target = $tree . '/' . $relative;
            $parent = \dirname($target);

            if (!is_dir($parent) && !@mkdir($parent, 0o777, true)) {
                throw new RuntimeException(\sprintf('Cannot create %s.', $parent));
            }

            if (is_link($source)) {
                if (!@symlink((string) readlink($source), $target)) {
                    throw new RuntimeException(\sprintf('Cannot recreate the symlink %s.', $relative));
                }

                ++$linked;

                continue;
            }

            if (!is_file($source)) {
                continue;
            }

            if (!@link($source, $target)) {
                throw new RuntimeException(\sprintf('Cannot hardlink %s into the scratch tree.', $relative));
            }

            ++$linked;
        }

        if ($linked === 0) {
            throw new RuntimeException(
                'git listed no content for the scratch clone. An empty candidate tree would make every'
                . ' comparison vacuous while looking green: refusing to run.',
            );
        }
    }

    public function path(string $relative): string
    {
        return $this->tree . '/' . $relative;
    }

    public function remove(): void
    {
        if ($this->removed) {
            return;
        }

        $this->removed = true;
        Shell::removeRecursively($this->directory);
    }

    /** Cleanup on every exit path, including an uncaught error and an interrupt. */
    public static function removeAll(): void
    {
        foreach (self::$live as $scratch) {
            $scratch->remove();
        }
    }
}
