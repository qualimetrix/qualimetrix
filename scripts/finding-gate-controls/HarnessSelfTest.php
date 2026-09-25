<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use RuntimeException;

/**
 * The harness's own mechanics, checked without running a control.
 *
 * Kept apart from the gate's self-test because the dependency runs one way:
 * the harness drives the gate, and the gate must not load the harness to test
 * itself.
 */
final class HarnessSelfTest
{
    /** @var list<string> */
    private array $failures = [];

    /** @return list<string> */
    public function run(): array
    {
        $this->controlCloneOwnsItsRepository();

        return $this->failures;
    }

    /**
     * A control's clone is a repository of its own, also when the developer's
     * checkout is a linked worktree.
     *
     * There `.git` is a `gitdir:` pointer file, and the copy the clone used to
     * take of it pointed straight back: every control's gate then added, pruned
     * and removed its reference checkout in the developer's repository, several
     * at once. The linked worktree carries a commit and a staged file of its
     * own, so the clone is also held to reading as that checkout does.
     */
    private function controlCloneOwnsItsRepository(): void
    {
        $repository = null;
        $outside = null;
        $scratch = null;

        try {
            $repository = self::throwawayRepository();
            $outside = Shell::temporaryDirectory('harness-self-test-linked-worktree-');
            $linked = $outside . '/linked';
            $branch = self::git($repository, 'symbolic-ref', '--short', 'HEAD');
            self::git($repository, 'worktree', 'add', '--quiet', '-b', 'linked', $linked, 'HEAD');
            file_put_contents($linked . '/committed.txt', "linked\n");
            self::git($linked, 'add', 'committed.txt');
            self::git($linked, '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'linked');
            file_put_contents($linked . '/staged.txt', "staged\n");
            self::git($linked, 'add', 'staged.txt');
            $registered = self::registeredWorktrees($repository);

            $scratch = Scratch::cloneOf($linked);
            $this->same(
                realpath($scratch->tree . '/.git'),
                realpath(self::git($scratch->tree, 'rev-parse', '--path-format=absolute', '--git-common-dir')),
                'the clone of a linked worktree resolves to a repository of its own',
            );
            $this->same(
                self::git($linked, 'rev-parse', 'HEAD', $branch),
                self::git($scratch->tree, 'rev-parse', 'HEAD', $branch),
                'the clone reads the worktree\'s HEAD and every branch of its repository',
            );
            $this->same(
                self::git($linked, 'diff', '--cached', '--name-only'),
                self::git($scratch->tree, 'diff', '--cached', '--name-only'),
                'and the worktree\'s staged state',
            );

            self::git($scratch->tree, 'worktree', 'add', '--quiet', '--detach', $scratch->beside('reference') . '/tree', 'HEAD');
            $this->same(
                $registered,
                self::registeredWorktrees($repository),
                'a worktree the clone adds is registered in the clone, never in the repository it was cloned from',
            );
        } catch (RuntimeException $error) {
            $this->failures[] = 'a control\'s clone owns its repository (' . $error->getMessage() . ')';
        } finally {
            $scratch?->remove();

            if ($repository !== null) {
                foreach (self::registeredWorktrees($repository) as $path) {
                    Shell::run(['git', '-C', $repository, 'worktree', 'remove', '--force', '--force', $path], $repository);
                }

                Shell::removeRecursively($repository);
            }

            if ($outside !== null) {
                Shell::removeRecursively($outside);
            }
        }
    }

    /**
     * Never the developer's repository: a case that fails halfway would
     * otherwise leave behind exactly the registration it exists to refuse.
     * `vendor/` is committed so the linked worktree checks it out: a clone
     * refuses a tree without one.
     */
    private static function throwawayRepository(): string
    {
        $root = Shell::temporaryDirectory('harness-self-test-repository-');
        mkdir($root . '/vendor');
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($root . '/tracked.txt', "tracked\n");
        self::git($root, 'init', '--quiet');
        self::git($root, 'add', '--all');
        self::git($root, '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'fixture');

        return $root;
    }

    private static function git(string $directory, string ...$arguments): string
    {
        return trim(Shell::mustRun(['git', ...array_values($arguments)], $directory));
    }

    /**
     * Asked of git rather than of the filesystem: the defect is a registration
     * that outlives its directory.
     *
     * @return list<string>
     */
    private static function registeredWorktrees(string $repository): array
    {
        $listed = Shell::run(['git', '-C', $repository, 'worktree', 'list', '--porcelain'], $repository);
        $paths = [];

        foreach (explode("\n", $listed['stdout']) as $line) {
            if (str_starts_with($line, 'worktree ') && realpath(substr($line, 9)) !== realpath($repository)) {
                $paths[] = substr($line, 9);
            }
        }

        return $paths;
    }

    private function same(mixed $expected, mixed $actual, string $description): void
    {
        if ($expected !== $actual) {
            $this->failures[] = \sprintf(
                '%s (expected %s, got %s)',
                $description,
                json_encode($expected, \JSON_UNESCAPED_SLASHES),
                json_encode($actual, \JSON_UNESCAPED_SLASHES),
            );
        }
    }
}
