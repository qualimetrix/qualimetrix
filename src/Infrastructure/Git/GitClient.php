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
     * Returns the root directory of the git repository.
     */
    public function getRoot(): string
    {
        return trim($this->exec('git rev-parse --show-toplevel'));
    }

    /**
     * Returns the project root the client was constructed with.
     *
     * Note: this is the project root, not the git top-level — the two are
     * identical when the project sits at the repository root, but the project
     * root may be a strict subdirectory of the git tree.
     */
    public function getProjectRoot(): AbsolutePath
    {
        return $this->projectRoot;
    }

    /**
     * Gets files changed according to the given scope.
     *
     * @return list<ChangedFile>
     */
    public function getChangedFiles(string $scope): array
    {
        return match (true) {
            $scope === 'staged' => $this->getStagedFiles(),
            $scope === 'HEAD' => $this->getUncommittedFiles(),
            str_contains($scope, '...') => $this->getThreeDotDiff($scope),
            str_contains($scope, '..') => $this->getTwoDotDiff($scope),
            default => $this->getDiffFrom($scope),
        };
    }

    /**
     * Gets staged files (files in the index).
     *
     * @return list<ChangedFile>
     */
    private function getStagedFiles(): array
    {
        $output = $this->exec('git diff --cached --name-status');

        return $this->parseNameStatus($output);
    }

    /**
     * Gets uncommitted files (changes in working tree vs HEAD).
     *
     * @return list<ChangedFile>
     */
    private function getUncommittedFiles(): array
    {
        $output = $this->exec('git diff --name-status HEAD');

        return $this->parseNameStatus($output);
    }

    /**
     * Gets files changed in two-dot diff (ref1..ref2).
     *
     * @return list<ChangedFile>
     */
    private function getTwoDotDiff(string $range): array
    {
        $output = $this->exec(\sprintf('git diff --name-status %s', escapeshellarg($range)));

        return $this->parseNameStatus($output);
    }

    /**
     * Gets files changed in three-dot diff (ref1...ref2 - changes since merge-base).
     *
     * @return list<ChangedFile>
     */
    private function getThreeDotDiff(string $range): array
    {
        $output = $this->exec(\sprintf('git diff --name-status %s', escapeshellarg($range)));

        return $this->parseNameStatus($output);
    }

    /**
     * Gets files changed from ref to HEAD (shorthand: ref → ref..HEAD).
     *
     * @return list<ChangedFile>
     */
    private function getDiffFrom(string $ref): array
    {
        $range = \sprintf('%s..HEAD', $ref);
        $output = $this->exec(\sprintf('git diff --name-status %s', escapeshellarg($range)));

        return $this->parseNameStatus($output);
    }

    /**
     * Returns (and lazily caches) the git top-level for the current repository.
     */
    private function gitToplevel(): AbsolutePath
    {
        if ($this->gitToplevelCache === null) {
            $this->gitToplevelCache = AbsolutePath::fromString($this->getRoot());
        }

        return $this->gitToplevelCache;
    }

    /**
     * Parses git diff --name-status output.
     *
     * Format:
     * A    file.php           (added)
     * M    file.php           (modified)
     * D    file.php           (deleted)
     * R100 old.php new.php    (renamed)
     * C100 old.php new.php    (copied)
     *
     * Paths returned by git are git-toplevel-relative. They are translated to
     * project-relative form via {@see ChangedFile::fromGitOutput()}; rows whose
     * paths fall outside the project root are skipped and reported as a single
     * PSR-3 `warning` at the end of parsing.
     *
     * @return list<ChangedFile>
     */
    private function parseNameStatus(string $output): array
    {
        $gitToplevel = null;
        $files = [];
        $skippedPaths = [];
        $lines = array_filter(explode("\n", trim($output)), static fn(string $line): bool => $line !== '');

        foreach ($lines as $line) {
            // Standard: single-letter status\tpath (A/M/D/C and others like T/U/X)
            if (preg_match('/^([A-Z])\t(.+)$/', $line, $matches) === 1) {
                $status = ChangeStatus::tryFrom($matches[1]);
                // Skip unknown statuses (T=type change, U=unmerged, X=unknown, etc.)
                if ($status === null) {
                    continue;
                }

                $gitToplevel ??= $this->gitToplevel();
                $this->collectRow($matches[2], $status, null, $gitToplevel, $files, $skippedPaths);

                continue;
            }

            // Rename: R<similarity>\told\tnew
            if (preg_match('/^R\d*\t(.+)\t(.+)$/', $line, $matches) === 1) {
                $gitToplevel ??= $this->gitToplevel();
                $this->collectRow($matches[2], ChangeStatus::Renamed, $matches[1], $gitToplevel, $files, $skippedPaths);

                continue;
            }

            // Copy: C<similarity>\told\tnew
            if (preg_match('/^C\d*\t(.+)\t(.+)$/', $line, $matches) === 1) {
                $gitToplevel ??= $this->gitToplevel();
                $this->collectRow($matches[2], ChangeStatus::Copied, $matches[1], $gitToplevel, $files, $skippedPaths);

                continue;
            }
        }

        if ($skippedPaths !== []) {
            $this->logger->warning(
                \sprintf(
                    'Skipped %d changed file(s) outside project root (raw git paths shown — the project root sits in a git subdirectory): %s',
                    \count($skippedPaths),
                    implode(', ', $skippedPaths),
                ),
            );
        }

        return array_values(array_unique($files, \SORT_REGULAR));
    }

    /**
     * Translates one diff row via {@see ChangedFile::fromGitOutput()} and appends the
     * result to `$files`, or the raw new path to `$skippedPaths` when the row falls
     * outside the project root.
     *
     * @param list<ChangedFile> $files
     * @param list<string> $skippedPaths
     */
    private function collectRow(
        string $rawGitPath,
        ChangeStatus $status,
        ?string $rawOldGitPath,
        AbsolutePath $gitToplevel,
        array &$files,
        array &$skippedPaths,
    ): void {
        $changed = ChangedFile::fromGitOutput($rawGitPath, $status, $rawOldGitPath, $gitToplevel, $this->projectRoot);

        if ($changed === null) {
            $skippedPaths[] = $rawGitPath;

            return;
        }

        $files[] = $changed;
    }

    /**
     * Executes a git command and returns the output.
     *
     * @throws RuntimeException if the command fails
     */
    private function exec(string $command): string
    {
        $process = Process::fromShellCommandline(
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
}
