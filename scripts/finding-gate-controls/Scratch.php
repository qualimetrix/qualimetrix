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
 * repository the developer is working in. A caller with no repository to make
 * takes {@see contentOf()} and does not pay for it at all.
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

    /** A clone that is still a git repository, for a control that needs one. */
    public static function cloneOf(string $repository): self
    {
        $scratch = self::contentOf($repository);

        Shell::mustRun(['cp', '-R', $repository . '/.git', $scratch->tree . '/.git'], $repository);

        // A copied `.git` brings the repository's worktree registrations with
        // it, including the reference checkout of an earlier gate run that was
        // killed before it could deregister. Git refuses to add a worktree
        // whose name is already registered, and the gate always names its
        // reference `tree` — so an unrelated stale entry would turn a control
        // into a crash. The scratch tree's own registrations mean nothing.
        Shell::removeRecursively($scratch->tree . '/.git/worktrees');

        return $scratch;
    }

    /**
     * The same clone without `.git`: the files, and no history.
     *
     * `.git` is a real copy rather than a hardlink farm, so a caller that does
     * not need a repository pays 24M and 329 entries to make one and pays
     * again to remove it. The probe bench did exactly that, 116 times a run,
     * and the removal is why this costs nothing to skip: the tree it hands the
     * suite is the tree it handed before.
     *
     * A `git clone`, shallow or not, is not the cheaper version of this. What
     * is cloned here is the *working tree* — tracked files at their current
     * bytes plus untracked ones git does not ignore — and a clone of any depth
     * would silently substitute the last commit for what the developer is
     * looking at.
     */
    public static function contentOf(string $repository): self
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

    /**
     * A directory beside the cloned tree, inside the scratch that owns it.
     *
     * Beside and not within: a control plants its breakage in the tree and then
     * reads what a run says about that tree, so anything else written into it
     * moves the reading. Whatever is put here goes away with the clone.
     */
    public function beside(string $name): string
    {
        $path = $this->directory . '/' . $name;

        if (!is_dir($path) && !@mkdir($path, 0o700, true)) {
            throw new RuntimeException(\sprintf('Cannot create %s beside the clone.', $path));
        }

        return $path;
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
