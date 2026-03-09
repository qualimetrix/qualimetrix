<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Git;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Client for executing git commands.
 *
 * Provides methods to get changed files from various git scopes.
 */
final class GitClient
{
    public function __construct(
        private readonly string $repoRoot,
    ) {}

    /**
     * Returns true if the current directory is a git repository.
     */
    public function isRepository(): bool
    {
        // .git is a directory in regular repos, but a file in worktrees
        return is_dir($this->repoRoot . '/.git') || is_file($this->repoRoot . '/.git');
    }

    /**
     * Returns the root directory of the git repository.
     */
    public function getRoot(): string
    {
        return trim($this->exec('git rev-parse --show-toplevel'));
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
     * Parses git diff --name-status output.
     *
     * Format:
     * A    file.php           (added)
     * M    file.php           (modified)
     * D    file.php           (deleted)
     * R100 old.php new.php    (renamed)
     * C100 old.php new.php    (copied)
     *
     * @return list<ChangedFile>
     */
    private function parseNameStatus(string $output): array
    {
        $files = [];
        $lines = array_filter(explode("\n", trim($output)));

        foreach ($lines as $line) {
            // Standard: single-letter status\tpath (A/M/D/C and others like T/U/X)
            if (preg_match('/^([A-Z])\t(.+)$/', $line, $matches)) {
                $status = ChangeStatus::tryFrom($matches[1]);
                // Skip unknown statuses (T=type change, U=unmerged, X=unknown, etc.)
                if ($status === null) {
                    continue;
                }

                $files[] = new ChangedFile(
                    path: $matches[2],
                    status: $status,
                );

                continue;
            }

            // Rename: R<similarity>\told\tnew
            if (preg_match('/^R\d*\t(.+)\t(.+)$/', $line, $matches)) {
                $files[] = new ChangedFile(
                    path: $matches[2],
                    status: ChangeStatus::Renamed,
                    oldPath: $matches[1],
                );

                continue;
            }

            // Copy: C<similarity>\told\tnew
            if (preg_match('/^C\d*\t(.+)\t(.+)$/', $line, $matches)) {
                $files[] = new ChangedFile(
                    path: $matches[2],
                    status: ChangeStatus::Copied,
                    oldPath: $matches[1],
                );

                continue;
            }
        }

        return array_values(array_unique($files, \SORT_REGULAR));
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
            $this->repoRoot,
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
