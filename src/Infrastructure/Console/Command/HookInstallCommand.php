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
        $hookPath = $this->hookPath();

        $binary = $this->runningBinaryLocator->path();
        if ($binary === null) {
            throw $this->refusal(
                'Could not determine the path of the running qmx binary. The hook has to name it, so nothing was written.',
            );
        }

        $this->clearExistingHook($input, $output, $hookPath);

        $failure = self::write($hookPath, PreCommitHook::script($binary));
        if ($failure !== null) {
            throw $this->refusal(\sprintf('Failed to write hook: %s: %s', $hookPath, $failure));
        }

        $output->writeln('<info>✓ Pre-commit hook installed</info>');
        $output->writeln(\sprintf('Hook path: %s', $hookPath));
        $output->writeln(\sprintf('Runs: %s', $binary));
        $output->writeln('');
        $output->writeln('The hook will run Qualimetrix on staged PHP files before each commit.');
        $output->writeln('To bypass the hook, use: git commit --no-verify');

        return self::SUCCESS;
    }

    /** Makes room for a new hook, or refuses. */
    private function clearExistingHook(InputInterface $input, OutputInterface $output, string $hookPath): void
    {
        // A dangling symlink is a hook: `file_exists` follows the link and
        // says false for one, and earlier releases installed the hook as a
        // symlink into a script this package no longer carries. Treating that
        // as "no hook" would write through the link and recreate the script
        // outside the hooks directory.
        if (!self::hookExists($hookPath)) {
            return;
        }

        if ($input->getOption('force') !== true) {
            throw $this->refusal(\sprintf(
                'Pre-commit hook already exists: %s. Use --force to overwrite it; a hook that is not a Qualimetrix hook is backed up first.',
                $hookPath,
            ));
        }

        $this->backUp($output, $hookPath);

        // The link itself, not what it points at: `rename()` would replace the
        // target through it and leave the repository pointing at a file that
        // no longer belongs there.
        if (!is_link($hookPath)) {
            return;
        }

        [$removed, $reason] = self::attempt(static fn(): bool => unlink($hookPath));
        if (!$removed) {
            throw $this->refusal(\sprintf('Failed to remove the existing hook: %s: %s', $hookPath, $reason));
        }
    }

    /**
     * Preserves a hook that is not ours to lose.
     *
     * Only one that is not ours: `.backup` is a single slot, so backing up our
     * own generated hook on a second `--force` would overwrite the user's
     * original with a copy of something they can regenerate at will. A broken
     * symlink has no contents to preserve either.
     *
     * The slot is single because `hook:uninstall --restore-backup` reads it by
     * that one name. An occupied slot holding a different hook is therefore a
     * refusal, not an overwrite: forcing a second foreign hook over the first
     * would otherwise lose the first one, reported with the same success line.
     */
    private function backUp(OutputInterface $output, string $hookPath): void
    {
        if (!file_exists($hookPath)) {
            $output->writeln('<comment>Existing hook is a broken symlink; nothing to back up.</comment>');

            return;
        }

        $contents = @file_get_contents($hookPath);

        if ($contents !== false && PreCommitHook::isOurs($contents)) {
            $output->writeln('<comment>Replacing a Qualimetrix hook; the existing backup is left alone.</comment>');

            return;
        }

        $backupPath = $hookPath . '.backup';

        if (file_exists($backupPath) || is_link($backupPath)) {
            if ($contents !== false && @file_get_contents($backupPath) === $contents) {
                $output->writeln(\sprintf('<comment>The backup %s already holds this hook; it is left as it is.</comment>', $backupPath));

                return;
            }

            throw $this->refusal(\sprintf(
                'The backup %s already holds a different hook, and it is the only copy hook:uninstall --restore-backup can restore. '
                . 'Move it away, then run hook:install --force again.',
                $backupPath,
            ));
        }

        [$copied, $reason] = self::attempt(static fn(): bool => copy($hookPath, $backupPath));
        if (!$copied) {
            throw $this->refusal(\sprintf('Failed to back up the existing hook to %s: %s. The hook was left in place.', $backupPath, $reason));
        }

        $output->writeln(\sprintf('<info>Existing hook backed up to: %s</info>', $backupPath));
    }

    /**
     * Writes the hook executable, or leaves whatever was there untouched.
     *
     * Temporary file first, then rename: a hook half-written by an
     * interrupted run is a file git will still try to execute.
     *
     * @return string|null the system's reason the hook could not be written, or null once it is
     */
    private static function write(string $hookPath, string $contents): ?string
    {
        $temporaryPath = $hookPath . '.tmp.' . getmypid();

        // The length, not just `false`: a full disk writes part of the file
        // and reports how much, and a truncated hook is one git still runs.
        [$written, $reason] = self::attempt(static fn() => file_put_contents($temporaryPath, $contents));
        if ($written !== \strlen($contents)) {
            @unlink($temporaryPath);

            return $reason;
        }

        [$placed, $reason] = self::attempt(
            static fn(): bool => chmod($temporaryPath, 0755) && rename($temporaryPath, $hookPath),
        );
        if (!$placed) {
            @unlink($temporaryPath);

            return $reason;
        }

        return null;
    }
}
