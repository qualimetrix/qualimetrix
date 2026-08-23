<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The reference side: a detached worktree of a named ref, with the candidate's
 * installed dependencies.
 *
 * **Do not symlink `vendor/`.** Composer's generated
 * `vendor/composer/autoload_psr4.php` derives its base directory from `__DIR__`,
 * which PHP resolves through symlinks — so a symlinked vendor makes the
 * reference binary autoload the CANDIDATE's `src/`, and every comparison passes
 * for the wrong reason. Measured on 2026-08-23: the reference process loaded
 * `Qualimetrix\Core\Version` from the candidate tree. A hardlink clone puts a
 * real directory in the worktree, so the base directory is the worktree.
 *
 * The dependency set itself must be identical, or nothing the two trees output
 * is comparable; that check is fail-loud and has its own failure class.
 */
final class ReferenceTree
{
    private bool $removed = false;

    private function __construct(
        public readonly string $root,
        private readonly string $candidateRoot,
        private readonly string $temporaryDirectory,
    ) {}

    public static function create(string $candidateRoot, string $reference): self
    {
        $temporaryDirectory = Fs::temporaryDirectory('finding-gate-ref-');
        $root = $temporaryDirectory . '/tree';
        $added = Process::run(['git', '-C', $candidateRoot, 'worktree', 'add', '--detach', $root, $reference], $candidateRoot);

        if ($added['exit'] !== 0) {
            Fs::removeRecursively($temporaryDirectory);

            throw new GateError(\sprintf("Cannot check out reference \"%s\":\n%s", $reference, $added['stderr']));
        }

        $tree = new self($root, $candidateRoot, $temporaryDirectory);
        $tree->installVendor();

        return $tree;
    }

    public function dependencySetMismatch(): ?string
    {
        $candidate = $this->candidateRoot . '/composer.lock';
        $reference = $this->root . '/composer.lock';

        if (!is_file($reference)) {
            return 'The reference tree has no composer.lock.';
        }

        $candidateHash = hash('sha256', Fs::read($candidate));
        $referenceHash = hash('sha256', Fs::read($reference));

        if ($candidateHash === $referenceHash) {
            return null;
        }

        return \sprintf(
            'composer.lock differs (candidate %s, reference %s). The reference tree runs against the candidate\'s'
            . ' installed dependencies, so a different lock means the two sides do not share a dependency set and'
            . ' no artifact comparison between them means anything.',
            substr($candidateHash, 0, 12),
            substr($referenceHash, 0, 12),
        );
    }

    public function remove(): void
    {
        if ($this->removed) {
            return;
        }

        $this->removed = true;

        // The hardlinked vendor shares inodes with the candidate's; only the
        // links go away, never the files' content.
        $removed = Process::run(['git', '-C', $this->candidateRoot, 'worktree', 'remove', '--force', $this->root], $this->candidateRoot);
        Fs::removeRecursively($this->temporaryDirectory);

        // A removal whose exit code nobody reads leaves the worktree registered
        // in the real repository after its directory is gone: `git worktree
        // list` then names a path that does not exist, and the next run of
        // anything that walks worktrees inherits the mess. Observed once, and it
        // took a manual `git worktree prune` to clear.
        $pruned = Process::run(['git', '-C', $this->candidateRoot, 'worktree', 'prune'], $this->candidateRoot);

        if ($removed['exit'] !== 0 && $pruned['exit'] !== 0) {
            throw new GateError(\sprintf(
                "Cannot deregister the reference worktree at %s:\n%s%s",
                $this->root,
                $removed['stderr'],
                $pruned['stderr'],
            ));
        }
    }

    private function installVendor(): void
    {
        $source = $this->candidateRoot . '/vendor';
        $target = $this->root . '/vendor';

        if (!is_dir($source)) {
            throw new GateError(\sprintf('%s has no vendor/ to clone into the reference tree.', $this->candidateRoot));
        }

        foreach ([['cp', '-Rl', $source, $target], ['cp', '-R', $source, $target]] as $command) {
            Fs::removeRecursively($target);

            if (Process::run($command, $this->candidateRoot)['exit'] === 0) {
                return;
            }
        }

        throw new GateError(\sprintf('Cannot clone %s into %s.', $source, $target));
    }
}
