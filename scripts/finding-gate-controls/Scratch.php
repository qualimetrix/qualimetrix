<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use RuntimeException;

/**
 * A scratch candidate tree: a hardlink clone of the *working tree*, not of a
 * commit.
 *
 * Two reasons it cannot be a `git worktree`. The corpus and the gate are
 * often uncommitted while controls are being developed, so a checkout of HEAD
 * would not contain the input under test. A control must also plant its breakage in a tree
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
 * The repository is a clone of its own, never the developer's: the gate
 * creates and removes a worktree inside the candidate's repository, and no
 * control may reach into the repository the developer is working in. See
 * {@see cloneOf()} for why it is a clone and not a copy of `.git`. A caller
 * with no repository to make takes {@see contentOf()} and does not pay for it
 * at all.
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

    /**
     * A clone that is still a git repository, for a control that needs one.
     *
     * The repository is a local `git clone --mirror` of the developer's common
     * git directory, with the checkout's own `HEAD` and `index` copied over it,
     * so every ref, a detached `HEAD` and the staged state read as they do in
     * the checkout. Not a copy of `.git`, for two measured reasons. In a linked
     * worktree `.git` is a `gitdir:` pointer file, and a copy of it made every
     * control's gate add, prune and remove its reference worktree in the
     * developer's repository, concurrently with the other controls. And in the
     * main checkout `.git` is 617M and 75k entries on 2026-09-25, almost all of
     * it review material kept under `.git/`, which a clone does not carry: it
     * takes `objects/` and `refs/` only, and hardlinks the objects, which git
     * never writes in place. It also inherits no worktree registration.
     */
    public static function cloneOf(string $repository): self
    {
        $scratch = self::contentOf($repository);
        $git = $scratch->tree . '/.git';
        $common = trim(Shell::mustRun(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], $repository));

        Shell::mustRun(['git', 'clone', '--quiet', '--mirror', $common, $git], $repository);

        // The mirror's remote would push every ref back into the developer's
        // repository. Removed as configuration only: `git remote remove`
        // deletes the refs a mirror's refspec maps, which is all of them.
        Shell::mustRun(['git', 'config', '--remove-section', 'remote.origin'], $scratch->tree);
        Shell::mustRun(['git', 'config', 'core.bare', 'false'], $scratch->tree);

        foreach (['HEAD', 'index'] as $file) {
            $source = trim(Shell::mustRun(['git', 'rev-parse', '--path-format=absolute', '--git-path', $file], $repository));

            if (is_file($source)) {
                Shell::mustRun(['cp', $source, $git . '/' . $file], $repository);
            }
        }

        $resolved = trim(Shell::mustRun(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], $scratch->tree));

        if (realpath($resolved) !== realpath($git)) {
            throw new RuntimeException(\sprintf(
                'The scratch clone at %s resolves its repository to %s rather than its own .git: a control'
                . ' would reach into a repository it does not own. Refusing to run.',
                $scratch->tree,
                $resolved,
            ));
        }

        return $scratch;
    }

    /**
     * The same clone without `.git`: the files, and no history.
     *
     * A caller that does not need a repository would otherwise pay to make one
     * and pay again to remove it. The probe bench did exactly that, 116 times
     * a run, and the removal is why this costs nothing to skip: the tree it
     * hands the suite is the tree it handed before.
     *
     * A `git clone` with a checkout, shallow or not, is not the cheaper version
     * of this. What is cloned here is the *working tree* — tracked files at
     * their current bytes plus untracked ones git does not ignore — and a
     * checkout of any depth would silently substitute the last commit for what
     * the developer is looking at.
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
