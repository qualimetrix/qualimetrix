<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Infrastructure\Console\Hook\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:install',
    description: 'Install git pre-commit hook for Qualimetrix',
)]
final class HookInstallCommand extends Command
{
    public function __construct(
        private readonly GitRepositoryLocatorInterface $gitRepositoryLocator,
        private readonly RunningBinaryLocatorInterface $runningBinaryLocator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite existing hook',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $gitDir = $this->gitRepositoryLocator->findGitDir();
        if ($gitDir === null) {
            $output->writeln('<error>Not a git repository. Initialize git first with: git init</error>');

            return self::FAILURE;
        }

        $hooksDir = $gitDir->joinRelative(RelativePath::fromString('hooks'))->value();
        if (!is_dir($hooksDir)) {
            $output->writeln('<error>Git hooks directory not found: ' . $hooksDir . '</error>');

            return self::FAILURE;
        }

        $binary = $this->runningBinaryLocator->path();
        if ($binary === null) {
            $output->writeln('<error>Could not determine the path of the running qmx binary.</error>');
            $output->writeln('The hook has to name it, so nothing was written.');

            return self::FAILURE;
        }

        $hookPath = $hooksDir . '/pre-commit';

        $refusal = $this->clearExistingHook($input, $output, $hookPath);
        if ($refusal !== null) {
            return $refusal;
        }

        if (!$this->write($hookPath, PreCommitHook::script($binary))) {
            $output->writeln('<error>Failed to write hook: ' . $hookPath . '</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Pre-commit hook installed</info>');
        $output->writeln(\sprintf('Hook path: %s', $hookPath));
        $output->writeln(\sprintf('Runs: %s', $binary));
        $output->writeln('');
        $output->writeln('The hook will run Qualimetrix on staged PHP files before each commit.');
        $output->writeln('To bypass the hook, use: git commit --no-verify');

        return self::SUCCESS;
    }

    /**
     * Makes room for a new hook, or refuses.
     *
     * @return int|null a command exit code to return, or null to carry on
     */
    private function clearExistingHook(InputInterface $input, OutputInterface $output, string $hookPath): ?int
    {
        // A dangling symlink is a hook: `file_exists` follows the link and
        // says false for one, and earlier releases installed the hook as a
        // symlink into a script this package no longer carries. Treating that
        // as "no hook" would write through the link and recreate the script
        // outside the hooks directory.
        if (!is_link($hookPath) && !file_exists($hookPath)) {
            return null;
        }

        if ($input->getOption('force') !== true) {
            $output->writeln('<comment>Pre-commit hook already exists.</comment>');
            $output->writeln('Use --force to overwrite.');

            return self::FAILURE;
        }

        if (file_exists($hookPath)) {
            $backupPath = $hookPath . '.backup';

            if (!copy($hookPath, $backupPath)) {
                $output->writeln('<error>Failed to backup existing hook</error>');

                return self::FAILURE;
            }

            $output->writeln(\sprintf('<info>Existing hook backed up to: %s</info>', $backupPath));
        } else {
            $output->writeln('<comment>Existing hook is a broken symlink; nothing to back up.</comment>');
        }

        if (!unlink($hookPath)) {
            $output->writeln('<error>Failed to remove existing hook</error>');

            return self::FAILURE;
        }

        return null;
    }

    /**
     * Writes the hook executable, or leaves whatever was there untouched.
     *
     * Temporary file first, then rename: a hook half-written by an
     * interrupted run is a file git will still try to execute.
     */
    private function write(string $hookPath, string $contents): bool
    {
        $temporaryPath = $hookPath . '.tmp.' . getmypid();

        if (file_put_contents($temporaryPath, $contents) === false) {
            return false;
        }

        if (!chmod($temporaryPath, 0755) || !rename($temporaryPath, $hookPath)) {
            unlink($temporaryPath);

            return false;
        }

        return true;
    }
}
