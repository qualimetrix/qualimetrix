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
 * {@see parseNameStatus()} via {@see ChangedFile::fromGitOutput()}.
 */
final class GitClient
{
    /**
     * The measured fact, not a diagnosis of it. A project root inside a git
     * subdirectory is the common cause and not the only one, so the causes are
     * listed rather than asserted.
     */
    private const string SKIPPED_OUTSIDE_ROOT =
        'whose path did not resolve inside the project root — the project root may be a subdirectory of the git tree, '
        . 'or the path may point outside it through a link';

    private const string SKIPPED_UNREPRESENTABLE =
        'whose name holds a backslash, which this build\'s relative-path model rewrites as a directory separator '
        . '(the file is real; the name cannot be carried through unchanged)';

    /**
     * `U` is the one refused status with a cause the user can act on: finish
     * the merge. An unmerged entry is not a version of a file — the index
     * holds two or three of them at once — so there is nothing here to hand
     * downstream as "the changed file".
     */
    private const string SKIPPED_UNMERGED =
        'left unmerged in the index by a conflict git has not been told is resolved — '
        . 'such an entry names no single version to analyse';

    /**
     * The catch-all, and deliberately a format string: the letter is the whole
     * of what is known, so it is quoted back rather than interpreted. `X` and
     * any letter a later git adds arrive here.
     */
    private const string SKIPPED_UNSUPPORTED_STATUS =
        'reported by git under status "%s", which this build does not know how to carry';

    private const string SOURCE_UNREPRESENTABLE =
        'whose source name holds a backslash and cannot be carried through this build\'s relative-path model. '
        . 'The file itself is analysed; only the name it was moved from is lost';

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
     * Turns the NUL-separated `git diff --name-status -z` stream into files.
     *
     * The stream is walked by {@see NameStatusRecord::parseStream()}; what
     * happens here is the project's own judgement on each record. Paths
     * returned by git are git-toplevel-relative and are translated to
     * project-relative form via {@see ChangedFile::fromGitOutput()}.
     *
     * A record this run cannot carry does not disappear: it is collected under
     * the reason it was dropped for, and each reason becomes one PSR-3
     * `warning` at the end of parsing. There is no silent path out of this
     * loop — a status letter the enum does not name is quoted back in its own
     * reason, so a later git letter is reported rather than lost.
     *
     * @return list<ChangedFile>
     */
    private function parseNameStatus(string $output): array
    {
        $gitToplevel = null;
        $files = [];

        /** @var array<string, list<string>> $skipped */
        $skipped = [];

        /** @var list<string> $lostSources */
        $lostSources = [];

        foreach (NameStatusRecord::parseStream($output) as $record) {
            $status = ChangeStatus::tryFrom($record->status);

            if ($status === null) {
                $skipped[self::refusedStatusReason($record->status)][] = $record->rawPath;

                continue;
            }

            if (!ChangedFile::isRepresentableGitPath($record->rawPath)) {
                $skipped[self::SKIPPED_UNREPRESENTABLE][] = $record->rawPath;

                continue;
            }

            if ($record->rawOldPath !== null && !ChangedFile::isRepresentableGitPath($record->rawOldPath)) {
                $lostSources[] = $record->rawOldPath;
            }

            $gitToplevel ??= $this->gitToplevel();
            $changed = ChangedFile::fromGitOutput(
                $record->rawPath,
                $status,
                $record->rawOldPath,
                $gitToplevel,
                $this->projectRoot,
            );

            if ($changed === null) {
                $skipped[self::SKIPPED_OUTSIDE_ROOT][] = $record->rawPath;

                continue;
            }

            $files[] = $changed;
        }

        $this->reportSkipped($skipped);
        $this->reportLostSources($lostSources);

        return array_values(array_unique($files, \SORT_REGULAR));
    }

    /**
     * Why a status letter the enum does not name was refused.
     *
     * `U` gets its own sentence because its cause is actionable and its shape
     * is understood; everything else is named by its letter alone, which is
     * all this build knows about it.
     */
    private static function refusedStatusReason(string $status): string
    {
        return $status === 'U'
            ? self::SKIPPED_UNMERGED
            : \sprintf(self::SKIPPED_UNSUPPORTED_STATUS, $status);
    }

    /**
     * One warning per reason, naming the measured fact first.
     *
     * The old single message named a subdirectory project root as *the* cause
     * of a path that would not relativize. It is one cause of several, and a
     * message that states a diagnosis the code did not measure sends the
     * reader looking in the wrong place.
     *
     * @param array<string, list<string>> $skipped
     */
    private function reportSkipped(array $skipped): void
    {
        foreach ($skipped as $reason => $paths) {
            $this->logger->warning(\sprintf(
                'Skipped %d changed file(s) %s. Raw git paths: %s',
                \count($paths),
                $reason,
                implode(', ', $paths),
            ));
        }
    }

    /**
     * The rename whose source name was lost, reported apart from the drops.
     *
     * Nothing downstream reads `oldPath` today, so this costs the run nothing
     * — which is the reason to say it rather than not to. The same condition
     * on the *new* name drops the record and is reported as a drop; saying
     * nothing here would make the two outcomes of one check look like one.
     *
     * @param list<string> $paths
     */
    private function reportLostSources(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $this->logger->warning(\sprintf(
            'Kept %d changed file(s) %s. Raw git paths: %s',
            \count($paths),
            self::SOURCE_UNREPRESENTABLE,
            implode(', ', $paths),
        ));
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
