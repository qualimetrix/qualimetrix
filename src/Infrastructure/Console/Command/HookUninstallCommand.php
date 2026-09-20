<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:uninstall',
    description: 'Uninstall git pre-commit hook for Qualimetrix',
)]
final class HookUninstallCommand extends Command
{
    public function __construct(
        private readonly GitRepositoryLocatorInterface $gitRepositoryLocator,
    ) {
        parent::__construct();
    }

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
        $gitDir = $this->gitRepositoryLocator->findGitDir();
        if ($gitDir === null) {
            $output->writeln('<error>Not a git repository</error>');

            return self::FAILURE;
        }

        $hookPath = $gitDir->joinRelative(RelativePath::fromString('hooks/pre-commit'))->value();

        // is_link first: `file_exists` follows a symlink and answers false
        // for a broken one. A hook installed by an earlier release points at a
        // script this package no longer ships, and reporting it as absent
        // would leave git running a link that leads nowhere.
        if (!is_link($hookPath) && !file_exists($hookPath)) {
            $output->writeln('<comment>Pre-commit hook not found. Nothing to uninstall.</comment>');

            return self::SUCCESS;
        }

        $removeResult = $this->removeHookFile($hookPath, $output);
        if ($removeResult !== self::SUCCESS) {
            return $removeResult;
        }

        if ($input->getOption('restore-backup') === true) {
            return $this->restoreBackup($hookPath, $output);
        }

        $this->notifyBackupExists($hookPath, $output);

        return self::SUCCESS;
    }

    private function removeHookFile(string $hookPath, OutputInterface $output): int
    {
        // A link leading nowhere has no contents, so the only test for
        // ownership there is cannot be applied. Guessing from the link target
        // would mean carrying a rule about where a past release pointed it;
        // saying so and letting the user decide costs nothing and is never
        // wrong about someone else's hook.
        if (is_link($hookPath) && !file_exists($hookPath)) {
            $output->writeln('<error>Pre-commit hook is a symlink that leads nowhere.</error>');
            $output->writeln('Nothing identifies it, so it is left alone.');
            $output->writeln('Replace it with a working hook: bin/qmx hook:install --force');
            $output->writeln('Or remove it by hand: rm ' . $hookPath);

            return self::FAILURE;
        }

        $content = file_get_contents($hookPath);
        if ($content === false) {
            $output->writeln('<error>Failed to read hook file</error>');

            return self::FAILURE;
        }

        if (!PreCommitHook::isOurs($content)) {
            $output->writeln('<error>Pre-commit hook exists but is not an Qualimetrix hook.</error>');
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

}
