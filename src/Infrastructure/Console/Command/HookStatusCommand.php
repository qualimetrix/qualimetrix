<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:status',
    description: 'Show status of git pre-commit hook',
)]
final class HookStatusCommand extends Command
{
    /**
     * Marker comment to identify our hook.
     */
    private const HOOK_MARKER = 'AI Mess Detector pre-commit hook';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Find .git directory
        $gitDir = $this->findGitDir();
        if ($gitDir === null) {
            $output->writeln('<error>Not a git repository</error>');
            $output->writeln('');
            $output->writeln('Initialize git first: git init');

            return self::FAILURE;
        }

        $output->writeln('<info>Git Pre-commit Hook Status</info>');
        $output->writeln('');

        $hookPath = $gitDir . '/hooks/pre-commit';

        // Check if hook exists
        if (!file_exists($hookPath)) {
            $output->writeln('Status: <comment>NOT INSTALLED</comment>');
            $output->writeln('');
            $output->writeln('To install the hook, run:');
            $output->writeln('  bin/aimd hook:install');

            return self::SUCCESS;
        }

        // Hook exists - gather information
        $isSymlink = is_link($hookPath);
        $content = file_get_contents($hookPath);
        $isOurHook = $content !== false && str_contains($content, self::HOOK_MARKER);
        $isExecutable = is_executable($hookPath);

        $output->writeln('Status: <info>INSTALLED</info>');
        $output->writeln(\sprintf('Path: %s', $hookPath));

        if ($isSymlink) {
            $target = readlink($hookPath);
            $output->writeln(\sprintf('Type: <info>Symlink</info> → %s', $target === false ? 'unknown' : $target));
        } else {
            $output->writeln('Type: <info>Copy</info>');
        }

        if ($isOurHook) {
            $output->writeln('Owner: <info>AI Mess Detector</info>');
        } else {
            $output->writeln('Owner: <comment>Third-party hook</comment>');
            $output->writeln('');
            $output->writeln('<comment>Warning: This is not an AI Mess Detector hook.</comment>');
            $output->writeln('It may have been installed by another tool or manually.');
        }

        if ($isExecutable) {
            $output->writeln('Executable: <info>Yes</info>');
        } else {
            $output->writeln('Executable: <error>No</error>');
            $output->writeln('');
            $output->writeln('<error>Warning: Hook is not executable and will not run.</error>');
            $output->writeln(\sprintf('Fix with: chmod +x %s', $hookPath));
        }

        // Check for backup
        $backupPath = $hookPath . '.backup';
        if (file_exists($backupPath)) {
            $output->writeln('Backup: <info>Yes</info>');
            $output->writeln(\sprintf('Backup path: %s', $backupPath));
        } else {
            $output->writeln('Backup: <comment>No</comment>');
        }

        $output->writeln('');

        // Show suggestions based on status
        if ($isOurHook) {
            $output->writeln('The hook will run AI Mess Detector on staged PHP files before each commit.');
            $output->writeln('To bypass the hook, use: git commit --no-verify');
        } else {
            $output->writeln('To install AI Mess Detector hook, run:');
            $output->writeln('  bin/aimd hook:install --force');
        }

        return self::SUCCESS;
    }

    /**
     * Find .git directory in current directory or parent directories.
     *
     * @return string|null Absolute path to .git directory or null if not found
     */
    private function findGitDir(): ?string
    {
        $currentDir = getcwd();
        if ($currentDir === false) {
            return null;
        }

        // Check current directory and up to 5 parent directories
        $maxDepth = 5;
        $depth = 0;

        while ($depth < $maxDepth) {
            $gitDir = $currentDir . '/.git';

            if (is_dir($gitDir)) {
                return $gitDir;
            }

            // Move to parent directory
            $parentDir = \dirname($currentDir);
            if ($parentDir === $currentDir) {
                // Reached root directory
                break;
            }

            $currentDir = $parentDir;
            ++$depth;
        }

        return null;
    }
}
