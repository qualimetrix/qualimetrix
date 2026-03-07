<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:uninstall',
    description: 'Uninstall git pre-commit hook for AI Mess Detector',
)]
final class HookUninstallCommand extends Command
{
    /**
     * Marker comment to identify our hook.
     */
    private const HOOK_MARKER = 'AI Mess Detector pre-commit hook';

    protected function configure(): void
    {
        $this->addOption(
            'restore-backup',
            'r',
            InputOption::VALUE_NONE,
            'Restore backup if it exists',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Find .git directory
        $gitDir = $this->findGitDir();
        if ($gitDir === null) {
            $output->writeln('<error>Not a git repository</error>');

            return self::FAILURE;
        }

        $hookPath = $gitDir . '/hooks/pre-commit';

        if (!file_exists($hookPath)) {
            $output->writeln('<comment>Pre-commit hook not found. Nothing to uninstall.</comment>');

            return self::SUCCESS;
        }

        $removeResult = $this->removeHookFile($hookPath, $output);
        if ($removeResult !== self::SUCCESS) {
            return $removeResult;
        }

        if ($input->getOption('restore-backup')) {
            return $this->restoreBackup($hookPath, $output);
        }

        $this->notifyBackupExists($hookPath, $output);

        return self::SUCCESS;
    }

    private function removeHookFile(string $hookPath, OutputInterface $output): int
    {
        $content = file_get_contents($hookPath);
        if ($content === false) {
            $output->writeln('<error>Failed to read hook file</error>');

            return self::FAILURE;
        }

        if (!str_contains($content, self::HOOK_MARKER)) {
            $output->writeln('<error>Pre-commit hook exists but is not an AI Mess Detector hook.</error>');
            $output->writeln('Will not remove third-party hook. Remove it manually if needed.');

            return self::FAILURE;
        }

        if (!unlink($hookPath)) {
            $output->writeln('<error>Failed to remove hook file</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Pre-commit hook removed</info>');

        return self::SUCCESS;
    }

    private function restoreBackup(string $hookPath, OutputInterface $output): int
    {
        $backupPath = $hookPath . '.backup';

        if (!file_exists($backupPath)) {
            $output->writeln('<comment>No backup found to restore</comment>');

            return self::SUCCESS;
        }

        if (!copy($backupPath, $hookPath)) {
            $output->writeln('<error>Failed to restore backup</error>');

            return self::FAILURE;
        }

        if (!chmod($hookPath, 0755)) {
            $output->writeln('<error>Failed to make restored hook executable</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Backup restored</info>');

        return self::SUCCESS;
    }

    private function notifyBackupExists(string $hookPath, OutputInterface $output): void
    {
        $backupPath = $hookPath . '.backup';
        if (!file_exists($backupPath)) {
            return;
        }

        $output->writeln('');
        $output->writeln(\sprintf('Backup file exists: %s', $backupPath));
        $output->writeln('Use --restore-backup to restore it.');
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
