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
 *
 * The aggregation vocabulary is compared here too, and here rather than in the
 * gate's own sequence on purpose: forward translation expands a metric-keys row
 * over the strategies of the tree it is applied to, which is THIS one, and a
 * strategy the step removed would otherwise fall out of every row while the
 * reference is still publishing it. Making it a step the gate has to remember
 * would make it a step one refactoring can drop; making it a condition of
 * obtaining a reference tree at all cannot be dropped without noticing.
 */
final class ReferenceTree
{
    private bool $removed = false;

    /** @var (callable(): void)|null */
    private $cancelHold;

    private function __construct(
        public readonly string $root,
        private readonly string $candidateRoot,
        private readonly string $temporaryDirectory,
    ) {}

    public static function create(string $candidateRoot, string $reference): self
    {
        $temporaryDirectory = Fs::temporaryDirectory('finding-gate-ref-');
        $root = $temporaryDirectory . '/tree';
        $tree = new self($root, $candidateRoot, $temporaryDirectory);

        // Held before the checkout exists, not after it succeeds. The path is
        // known in advance, removing one that was never registered is a no-op,
        // and the window this closes is the expensive one: a `git worktree add`
        // killed halfway leaves `locked = initializing`, which `prune` skips and
        // a single `--force` refuses. Registering afterwards also left the whole
        // of installVendor() below outside anything that releases — measured
        // 2026-09-14, a tree with no vendor/ threw and stayed registered.
        $tree->cancelHold = Scratch::hold($tree->remove(...));

        // Deregisters reference checkouts orphaned before any of this existed.
        // Only entries whose directory is already gone, so a sibling run's live
        // tree is never touched; a locked one is not touched either, which is
        // why removal below needs its second --force rather than this.
        Process::run(['git', '-C', $candidateRoot, 'worktree', 'prune'], $candidateRoot);

        $added = Process::run(['git', '-C', $candidateRoot, 'worktree', 'add', '--detach', $root, $reference], $candidateRoot);

        if ($added['exit'] !== 0) {
            $tree->remove();

            throw new GateError(\sprintf("Cannot check out reference \"%s\":\n%s", $reference, $added['stderr']));
        }

        try {
            MetricVocabulary::ofTree($candidateRoot)->assertSuffixesAgreeWith(MetricVocabulary::ofTree($root));
            $tree->installVendor();
        } catch (GateError $error) {
            $tree->remove();

            throw $error;
        }

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

    /**
     * Gives the checkout back: deregistered first, then the directory.
     *
     * `--force` twice, not once. A checkout whose `git worktree add` was killed
     * halfway is `locked = initializing`, and git refuses a single `--force` on
     * a locked tree with "use 'remove -f -f' to override" (measured on git
     * 2.55.0). The gate kills its own git when a run is interrupted, so that
     * state is one this tool produces rather than one it might meet.
     */
    public function remove(): void
    {
        if ($this->removed) {
            return;
        }

        Interruption::stopRaising();

        // The hardlinked vendor shares inodes with the candidate's; only the
        // links go away, never the files' content.
        $removed = Process::run(
            ['git', '-C', $this->candidateRoot, 'worktree', 'remove', '--force', '--force', $this->root],
            $this->candidateRoot,
        );
        Fs::removeRecursively($this->temporaryDirectory);
        $pruned = Process::run(['git', '-C', $this->candidateRoot, 'worktree', 'prune'], $this->candidateRoot);

        // Asked of git rather than inferred from exit codes. Both commands
        // exiting 0 is not the property that matters and does not imply it: a
        // locked entry makes `prune` exit 0 while the entry survives, so the
        // condition this replaces reported success for precisely the case that
        // needed reporting. What has to be true is that the path is no longer
        // registered, and only `git worktree list` knows.
        if ($this->isStillRegistered()) {
            throw new GateError(\sprintf(
                "The reference worktree at %s is still registered after removal:\n%s%s",
                $this->root,
                $removed['stderr'],
                $pruned['stderr'],
            ));
        }

        // Last, so that an exception above leaves the holding in place for the
        // backstop to retry. Set first, it would turn every later attempt into
        // a no-op on exactly the path that failed.
        $this->removed = true;
        ($this->cancelHold ?? static function (): void {})();
        $this->cancelHold = null;
    }

    private function isStillRegistered(): bool
    {
        $listed = Process::run(['git', '-C', $this->candidateRoot, 'worktree', 'list', '--porcelain'], $this->candidateRoot);

        if ($listed['exit'] !== 0) {
            throw new GateError(\sprintf(
                "Cannot read the worktree list of %s while releasing the reference checkout:\n%s",
                $this->candidateRoot,
                $listed['stderr'],
            ));
        }

        foreach (explode("\n", $listed['stdout']) as $line) {
            if ($line === 'worktree ' . $this->root) {
                return true;
            }
        }

        return false;
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
