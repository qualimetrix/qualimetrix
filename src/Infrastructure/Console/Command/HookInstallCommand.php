<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
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

        $this->write($hookPath, PreCommitHook::script($binary), $output);

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

        // Remove the link itself before resolving the replacement target;
        // otherwise the Core writer would resolve and replace its referent.
        if (!is_link($hookPath)) {
            return;
        }

        $entry = self::entry($hookPath);
        self::assertSameEntry($hookPath, $entry);
        [$removed, $reason] = self::attempt(static fn(): bool => unlink($hookPath));
        if (!$removed) {
            throw EnvironmentRefusal::aboutFile($hookPath, 'remove', $reason);
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
            if (!$this->danglingLink($hookPath, $output)) {
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook disappeared before backup');
            }

            $output->writeln('<comment>Existing hook is a broken symlink; nothing to back up.</comment>');
            return;
        }

        $target = $this->judge($hookPath, $output);
        $hookEntry = self::entry($hookPath);
        $contents = self::read($hookPath);

        if (PreCommitHook::isOurs($contents)) {
            $output->writeln('<comment>Replacing a Qualimetrix hook; the existing backup is left alone.</comment>');

            return;
        }

        $backupPath = $hookPath . '.backup';

        if ($this->backupAlreadyPreserves($backupPath, $contents, $output)) {
            return;
        }

        $sourcePath = $target->path?->value() ?? throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $hookPath, 'hook is not a regular file');
        $sourceEntry = self::entry($sourcePath);
        $mode = $sourceEntry['mode'] & 07777;
        $backupTarget = $this->judge($backupPath, $output);
        self::assertSameEntry($hookPath, $hookEntry);
        self::assertSameEntry($sourcePath, $sourceEntry);
        if (!$target->sameAs($this->judge($hookPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook changed before backup');
        }
        FileReplacement::replace($backupTarget, $contents, $mode, NewName::Exclusive);

        $output->writeln(\sprintf('<info>Existing hook backed up to: %s</info>', $backupPath));
    }

    private function backupAlreadyPreserves(string $backupPath, string $contents, OutputInterface $output): bool
    {
        if (!file_exists($backupPath) && !is_link($backupPath)) {
            return false;
        }

        $this->judge($backupPath, $output);
        if (self::read($backupPath) === $contents) {
            $output->writeln(\sprintf('<comment>The backup %s already holds this hook; it is left as it is.</comment>', $backupPath));

            return true;
        }

        throw $this->refusal(\sprintf(
            'The backup %s already holds a different hook, and it is the only copy hook:uninstall --restore-backup can restore. '
            . 'Move it away, then run hook:install --force again.',
            $backupPath,
        ));
    }

    private function write(string $hookPath, string $contents, OutputInterface $output): void
    {
        $target = $this->judge($hookPath, $output);
        if ($target->path?->value() !== $hookPath) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook name changed before installation');
        }

        FileReplacement::replace(
            $target,
            $contents,
            0755,
            $target->kind === TargetKind::Absent ? NewName::Exclusive : NewName::LastWriterWins,
        );
    }
}
