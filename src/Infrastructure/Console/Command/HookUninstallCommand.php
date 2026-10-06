<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

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
        if (!$this->files->exists($hookPath)) {
            $output->writeln('<comment>Pre-commit hook not found. Nothing to uninstall.</comment>');

            return self::SUCCESS;
        }

        $restore = $input->getOption('restore-backup') === true;
        $backup = $restore ? $this->files->backupToRestore($hookPath, $output) : null;
        switch ($this->files->removeOwnedHook($hookPath, $output)) {
            case 'dangling':
                throw $this->refusal(\sprintf(
                    'Pre-commit hook %s is a symlink that leads nowhere. Nothing identifies it, so it is left alone. '
                    . 'Replace it with a working hook: %s hook:install --force. Or remove it by hand: rm %s',
                    $hookPath,
                    $this->runningBinaryLocator->hint(),
                    $hookPath,
                ));
            case 'foreign':
                throw $this->refusal(\sprintf(
                    'Pre-commit hook %s is not a Qualimetrix hook, so it is left alone. Remove it by hand if it is no longer wanted.',
                    $hookPath,
                ));
            case 'removed':
                $output->writeln('<info>✓ Pre-commit hook removed</info>');
                break;
        }

        if ($restore) {
            if ($backup === null) {
                $output->writeln('<comment>No backup found to restore</comment>');
            } else {
                $this->files->restore($hookPath, $backup, $output);
                $output->writeln('<info>✓ Backup restored</info>');
            }

            return self::SUCCESS;
        }

        $this->notifyBackupExists($hookPath, $output);

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
}
