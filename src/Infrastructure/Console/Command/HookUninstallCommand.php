<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:uninstall',
    description: 'Uninstall git pre-commit hook for Qualimetrix',
)]
final class HookUninstallCommand extends AbstractHookCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'restore-backup',
            'r',
            InputOption::VALUE_NONE,
            'Restore backup if it exists',
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $hookPath = $this->hookPath();

        if (!self::hookExists($hookPath)) {
            $output->writeln('<comment>Pre-commit hook not found. Nothing to uninstall.</comment>');

            return self::SUCCESS;
        }

        $this->removeHookFile($hookPath, $output);

        if ($input->getOption('restore-backup') === true) {
            $this->restoreBackup($hookPath, $output);

            return self::SUCCESS;
        }

        $this->notifyBackupExists($hookPath, $output);

        return self::SUCCESS;
    }

    private function removeHookFile(string $hookPath, OutputInterface $output): void
    {
        // A link leading nowhere has no contents, so the only test for
        // ownership there is cannot be applied. Guessing from the link target
        // would mean carrying a rule about where a past release pointed it;
        // saying so and letting the user decide costs nothing and is never
        // wrong about someone else's hook.
        if (is_link($hookPath) && !file_exists($hookPath)) {
            throw $this->refusal(\sprintf(
                'Pre-commit hook %s is a symlink that leads nowhere. Nothing identifies it, so it is left alone. '
                . 'Replace it with a working hook: %s hook:install --force. Or remove it by hand: rm %s',
                $hookPath,
                $this->runningBinaryLocator->hint(),
                $hookPath,
            ));
        }

        $content = @file_get_contents($hookPath);
        if ($content === false) {
            throw $this->refusal(\sprintf('Failed to read hook file: %s', $hookPath));
        }

        if (!PreCommitHook::isOurs($content)) {
            throw $this->refusal(\sprintf(
                'Pre-commit hook %s is not a Qualimetrix hook, so it is left alone. Remove it by hand if it is no longer wanted.',
                $hookPath,
            ));
        }

        if (!unlink($hookPath)) {
            throw $this->refusal(\sprintf('Failed to remove hook file: %s', $hookPath));
        }

        $output->writeln('<info>✓ Pre-commit hook removed</info>');
    }

    private function restoreBackup(string $hookPath, OutputInterface $output): void
    {
        $backupPath = $hookPath . '.backup';

        if (!file_exists($backupPath)) {
            $output->writeln('<comment>No backup found to restore</comment>');

            return;
        }

        if (!copy($backupPath, $hookPath)) {
            throw $this->refusal(\sprintf('Failed to restore backup %s to %s', $backupPath, $hookPath));
        }

        if (!chmod($hookPath, 0755)) {
            throw $this->refusal(\sprintf('Failed to make restored hook executable: %s', $hookPath));
        }

        $output->writeln('<info>✓ Backup restored</info>');
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

}
