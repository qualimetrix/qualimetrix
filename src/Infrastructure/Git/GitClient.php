<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\Path\AbsolutePath;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Client for executing git commands.
 *
 * Provides methods to get changed files from various git scopes.
 *
 * Paths in the constructor argument are the **project root**, not the git
 * top-level. When the project sits in a subdirectory of the git tree, raw
 * paths from `git diff --name-status` are git-toplevel-relative and must be
 * eagerly translated to project-relative form — this happens in
 * {@see NameStatusListing::changedFiles()} via {@see ChangedFile::fromGitOutput()}.
 */
final class GitClient
{
    private ?AbsolutePath $gitToplevelCache = null;

    public function __construct(
        private readonly AbsolutePath $projectRoot,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Returns true if the current directory is a git repository.
     */
    public function isRepository(): bool
    {
        // .git is a directory in regular repos, but a file in worktrees
        $gitDir = $this->projectRoot->value() . '/.git';

        return is_dir($gitDir) || is_file($gitDir);
    }

    /**
     * Returns the root directory of the git repository (the `git rev-parse
     * --show-toplevel`). May differ from the project root the client was
     * constructed with when the project sits in a strict subdirectory of
     * the git tree.
     */
    public function getRoot(): AbsolutePath
    {
        // Only the terminator is stripped: `trim()` would also eat a trailing
        // space, and a directory is allowed to end in one.
        return AbsolutePath::fromString(rtrim($this->exec(['git', 'rev-parse', '--show-toplevel']), "\r\n"));
    }

    /**
     * Gets files changed according to the given scope.
     *
     * @return list<ChangedFile>
     */
    public function getChangedFiles(string $scope): array
    {
        $this->validateScope($scope);

        return match (true) {
            $scope === 'staged' => $this->getStagedFiles(),
            $scope === 'HEAD' => $this->getUncommittedFiles(),
            str_contains($scope, '...') => $this->getThreeDotDiff($scope),
            str_contains($scope, '..') => $this->getTwoDotDiff($scope),
            default => $this->getDiffFrom($scope),
        };
    }

    /**
     * Refuses, before any analysis runs, every scope git would refuse later.
     *
     * The set validated here has to equal the set that reaches git, or the
     * difference surfaces as a failed command rather than as a refusal. Two
     * shapes used to fall in that gap: `HEAD`, which returned early and then
     * failed in a repository with no commits, and a range of three or more
     * endpoints, whose parts each resolve while the range as a whole does not.
     *
     * `staged` is the one scope with nothing to resolve: `git diff --cached`
     * compares against the empty tree when there is no HEAD, which is a
     * correct answer rather than a failure.
     */
    public function validateScope(string $scope): void
    {
        $this->assertInsideWorkTree();

        if ($scope === 'staged') {
            return;
        }

        $separator = str_contains($scope, '...') ? '...' : (str_contains($scope, '..') ? '..' : null);
        $references = $separator === null ? [$scope] : explode($separator, $scope);

        if ($separator === null) {
            // `<ref>` is shorthand for `<ref>..HEAD`, and `HEAD` is asked for
            // on its own; either way HEAD is part of what reaches git.
            $references = array_values(array_unique([...$references, 'HEAD']));
        } elseif (\count($references) !== 2) {
            throw GitScopeRefusedException::notATwoEndpointRange($scope, \count($references));
        }

        foreach ($references as $reference) {
            $this->assertCommitReference($reference);
        }
    }

    private function assertCommitReference(string $reference): void
    {
        if ($reference === '') {
            throw new UnresolvedGitReferenceException($reference);
        }

        // Deliberately without `--quiet`. That flag suppresses the one line
        // saying why the revision was refused, and what it leaves behind does
        // not replace it: the exit code separates nothing, because a typo and
        // a damaged repository both reach this point as 128 with an empty
        // stderr. Measured over eighteen forms of refusal, every failure of
        // this command exits 128 and every one puts text on stderr, so there
        // is nothing here to classify and nothing to withhold — git's own
        // words are the answer, and they are relayed rather than read.
        // Relayed, so the environment is left alone and the text reaches the
        // user in the system's language; nothing reads it, so nothing breaks
        // when it is not English.
        $process = new Process(
            ['git', 'rev-parse', '--verify', $reference . '^{commit}'],
            $this->projectRoot->value(),
        );
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        throw new UnresolvedGitReferenceException($reference, trim($process->getErrorOutput()));
    }

    /**
     * Asserts that the project root is inside a git repository.
     *
     * `git diff --cached` outside a repository fails with an unrelated "unknown
     * option" error, so relying on the downstream command's failure text cannot
     * name the real problem. This probe is the single source of truth for the
     * "not a git repository" case and yields a message the user can act on.
     */
    private function assertInsideWorkTree(): void
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $this->projectRoot->value());
        $process->run();

        if ($process->isSuccessful() && trim($process->getOutput()) === 'true') {
            return;
        }

        throw new NotAGitRepositoryException();
    }

    /**
     * Gets staged files (files in the index).
     *
     * @return list<ChangedFile>
     */
    private function getStagedFiles(): array
    {
        return $this->parseNameStatus($this->diff(['--cached', '--name-status', '-z'], 'staged'));
    }

    /**
     * Gets uncommitted files (changes in working tree vs HEAD).
     *
     * @return list<ChangedFile>
     */
    private function getUncommittedFiles(): array
    {
        return $this->parseNameStatus($this->diff(['--name-status', '-z', 'HEAD'], 'HEAD'));
    }

    /**
     * Gets files changed in two-dot diff (ref1..ref2).
     *
     * @return list<ChangedFile>
     */
    private function getTwoDotDiff(string $range): array
    {
        return $this->parseNameStatus($this->diff(['--name-status', '-z', $range], $range));
    }

    /**
     * Gets files changed in three-dot diff (ref1...ref2 - changes since merge-base).
     *
     * @return list<ChangedFile>
     */
    private function getThreeDotDiff(string $range): array
    {
        return $this->parseNameStatus($this->diff(['--name-status', '-z', $range], $range));
    }

    /**
     * Gets files changed from ref to HEAD (shorthand: ref → ref..HEAD).
     *
     * @return list<ChangedFile>
     */
    private function getDiffFrom(string $ref): array
    {
        return $this->parseNameStatus(
            $this->diff(['--name-status', '-z', \sprintf('%s..HEAD', $ref)], $ref),
        );
    }

    /**
     * Returns (and lazily caches) the git top-level for the current repository.
     */
    private function gitToplevel(): AbsolutePath
    {
        return $this->gitToplevelCache ??= $this->getRoot();
    }

    /**
     * @return list<ChangedFile>
     */
    private function parseNameStatus(string $output): array
    {
        return NameStatusListing::changedFiles($output, $this->projectRoot, $this->gitToplevel(...), $this->logger);
    }

    /**
     * Executes a git command and returns its standard output.
     *
     * The command is an argument vector, so every element reaches git as one
     * literal argument. A ref or range is user input arriving from
     * `--report=git:...`, and git accepts refnames containing `;`, `|`, `&`,
     * `$(` and `>` — none of which this class has to quote.
     *
     * Reserved for commands that carry no user-supplied scope: a failure here
     * is the tool's problem, and stays an internal error. Everything driven by
     * `--report=git:...` goes through {@see diff()} instead.
     *
     * @param list<string> $command
     *
     * @throws RuntimeException if the command fails
     */
    private function exec(array $command): string
    {
        $process = new Process(
            $command,
            $this->projectRoot->value(),
        );

        try {
            $process->mustRun();

            return $process->getOutput();
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                \sprintf('Git command failed: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Runs one `git diff` for a user-supplied scope.
     *
     * The backstop under {@see validateScope()}: whatever that check missed,
     * the user still gets an input refusal quoting git rather than a Symfony
     * Process dump titled "Internal error". Validation is meant to leave
     * nothing for this branch to catch — a repository damaged between the two
     * commands is the only measured way in — so it is written as a condition
     * on the failure, not on which caller arrived.
     *
     * @param list<string> $arguments everything after `git diff`
     *
     * @throws GitScopeRefusedException if git refuses the command
     */
    private function diff(array $arguments, string $scope): string
    {
        $process = new Process(['git', 'diff', ...$arguments], $this->projectRoot->value());

        try {
            $process->mustRun();

            return $process->getOutput();
        } catch (ProcessFailedException) {
            throw GitScopeRefusedException::commandFailed($scope, trim($process->getErrorOutput()));
        }
    }
}
