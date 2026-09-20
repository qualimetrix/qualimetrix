<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Infrastructure\Console\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
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
    public function __construct(
        private readonly GitRepositoryLocatorInterface $gitRepositoryLocator,
        private readonly RunningBinaryLocatorInterface $runningBinaryLocator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $gitDir = $this->gitRepositoryLocator->findGitDir();
        if ($gitDir === null) {
            $output->writeln('<error>Not a git repository</error>');
            $output->writeln('');
            $output->writeln('Initialize git first: git init');

            return self::FAILURE;
        }

        $output->writeln('<info>Git Pre-commit Hook Status</info>');
        $output->writeln('');

        $hookPath = $gitDir->joinRelative(RelativePath::fromString('hooks/pre-commit'))->value();

        // is_link first: `file_exists` follows a symlink and answers false for
        // a broken one, which would report a hook git still tries to run as
        // absent.
        $isSymlink = is_link($hookPath);

        if (!$isSymlink && !file_exists($hookPath)) {
            $output->writeln('Status: <comment>NOT INSTALLED</comment>');
            $output->writeln('');
            $output->writeln('To install the hook, run:');
            $output->writeln(\sprintf('  %s hook:install', $this->runningBinaryLocator->hint()));

            return self::SUCCESS;
        }

        $output->writeln('Status: <info>INSTALLED</info>');
        $output->writeln(\sprintf('Path: %s', $hookPath));

        $contents = $isSymlink ? $this->reportSymlink($hookPath, $output) : $this->reportFile($hookPath, $output);

        if ($contents === null) {
            return self::SUCCESS;
        }

        $this->reportOwnership($contents, $output);
        $this->reportExecutable($hookPath, $output);
        $this->reportBackup($hookPath, $output);

        $output->writeln('');
        $this->reportSuggestions($contents, $output);

        return self::SUCCESS;
    }

    /**
     * @return string|null the hook's contents, or null when there is nothing
     *                     left to say about a link that leads nowhere
     */
    private function reportSymlink(string $hookPath, OutputInterface $output): ?string
    {
        $target = readlink($hookPath);
        $output->writeln(\sprintf('Type: <info>Symlink</info> → %s', $target === false ? 'unknown' : $target));

        $contents = file_exists($hookPath) ? file_get_contents($hookPath) : false;

        if ($contents === false) {
            $output->writeln('');
            $output->writeln('<error>Warning: the symlink leads nowhere, so this hook does nothing.</error>');
            $output->writeln('Earlier releases installed a symlink into a script this package no longer ships.');
            $output->writeln(\sprintf('Reinstall it: %s hook:install --force', $this->runningBinaryLocator->hint()));

            return null;
        }

        return $contents;
    }

    /** @return string|null null when the file cannot be read */
    private function reportFile(string $hookPath, OutputInterface $output): ?string
    {
        $output->writeln('Type: <info>File</info>');

        $contents = file_get_contents($hookPath);

        if ($contents === false) {
            $output->writeln('');
            $output->writeln('<error>Warning: the hook file cannot be read.</error>');

            return null;
        }

        return $contents;
    }

    private function reportOwnership(string $contents, OutputInterface $output): void
    {
        if (PreCommitHook::isOurs($contents)) {
            $output->writeln('Owner: <info>Qualimetrix</info>');

            return;
        }

        $output->writeln('Owner: <comment>Third-party hook</comment>');
        $output->writeln('');
        $output->writeln('<comment>Warning: This is not an Qualimetrix hook.</comment>');
        $output->writeln('It may have been installed by another tool or manually.');
    }

    private function reportExecutable(string $hookPath, OutputInterface $output): void
    {
        if (is_executable($hookPath)) {
            $output->writeln('Executable: <info>Yes</info>');

            return;
        }

        $output->writeln('Executable: <error>No</error>');
        $output->writeln('');
        $output->writeln('<error>Warning: Hook is not executable and will not run.</error>');
        $output->writeln(\sprintf('Fix with: chmod +x %s', $hookPath));
    }

    private function reportBackup(string $hookPath, OutputInterface $output): void
    {
        $backupPath = $hookPath . '.backup';

        if (file_exists($backupPath)) {
            $output->writeln('Backup: <info>Yes</info>');
            $output->writeln(\sprintf('Backup path: %s', $backupPath));

            return;
        }

        $output->writeln('Backup: <comment>No</comment>');
    }

    private function reportSuggestions(string $contents, OutputInterface $output): void
    {
        if (PreCommitHook::isOurs($contents)) {
            $output->writeln('The hook will run Qualimetrix on staged PHP files before each commit.');
            $output->writeln('To bypass the hook, use: git commit --no-verify');

            return;
        }

        $output->writeln('To install Qualimetrix hook, run:');
        $output->writeln(\sprintf('  %s hook:install --force', $this->runningBinaryLocator->hint()));
    }
}
