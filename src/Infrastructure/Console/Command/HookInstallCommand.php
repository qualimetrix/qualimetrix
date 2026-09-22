<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:install',
    description: 'Install git pre-commit hook for Qualimetrix',
)]
final class HookInstallCommand extends AbstractHookCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite existing hook',
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $hookPath = $this->hookPath($output);
        if ($hookPath === null) {
            return self::FAILURE;
        }

        $binary = $this->runningBinaryLocator->path();
        if ($binary === null) {
            $output->writeln('<error>Could not determine the path of the running qmx binary.</error>');
            $output->writeln('The hook has to name it, so nothing was written.');

            return self::FAILURE;
        }

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
        if (!self::hookExists($hookPath)) {
            return null;
        }

        if ($input->getOption('force') !== true) {
            $output->writeln('<comment>Pre-commit hook already exists.</comment>');
            $output->writeln('Use --force to overwrite.');

            return self::FAILURE;
        }

        $refusal = $this->backUp($output, $hookPath);

        if ($refusal !== null) {
            return $refusal;
        }

        // The link itself, not what it points at: `rename()` would replace the
        // target through it and leave the repository pointing at a file that
        // no longer belongs there.
        if (is_link($hookPath) && !unlink($hookPath)) {
            $output->writeln('<error>Failed to remove existing hook</error>');

            return self::FAILURE;
        }

        return null;
    }

    /**
     * Preserves a hook that is not ours to lose.
     *
     * Only one that is not ours: `.backup` is a single slot, so backing up our
     * own generated hook on a second `--force` would overwrite the user's
     * original with a copy of something they can regenerate at will. A broken
     * symlink has no contents to preserve either.
     *
     * @return int|null a command exit code to return, or null to carry on
     */
    private function backUp(OutputInterface $output, string $hookPath): ?int
    {
        if (!file_exists($hookPath)) {
            $output->writeln('<comment>Existing hook is a broken symlink; nothing to back up.</comment>');

            return null;
        }

        $contents = @file_get_contents($hookPath);

        if ($contents !== false && PreCommitHook::isOurs($contents)) {
            $output->writeln('<comment>Replacing a Qualimetrix hook; the existing backup is left alone.</comment>');

            return null;
        }

        $backupPath = $hookPath . '.backup';

        if (!copy($hookPath, $backupPath)) {
            $output->writeln('<error>Failed to backup existing hook</error>');

            return self::FAILURE;
        }

        $output->writeln(\sprintf('<info>Existing hook backed up to: %s</info>', $backupPath));

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

        // The length, not just `false`: a full disk writes part of the file
        // and reports how much, and a truncated hook is one git still runs.
        if (file_put_contents($temporaryPath, $contents) !== \strlen($contents)) {
            @unlink($temporaryPath);

            return false;
        }

        if (!chmod($temporaryPath, 0755) || !rename($temporaryPath, $hookPath)) {
            unlink($temporaryPath);

            return false;
        }

        return true;
    }
}
