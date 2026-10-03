<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
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

        $backupPath = $hookPath . '.backup';
        $restore = $input->getOption('restore-backup') === true;
        $backupTarget = null;
        $backupEntry = null;
        if ($restore && self::hookExists($backupPath)) {
            $backupTarget = $this->judge($backupPath, $output);
            if (is_link($backupPath) || $backupTarget->kind !== TargetKind::Regular || $backupTarget->path?->value() !== $backupPath) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $backupPath, 'backup must be a regular file in the hooks directory');
            }
            $backupEntry = self::entry($backupPath);
        }

        $this->removeHookFile($hookPath, $output);

        if ($restore) {
            $this->restoreBackup($hookPath, $output, $backupTarget, $backupEntry);

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
        if ($this->danglingLink($hookPath, $output)) {
            throw $this->refusal(\sprintf(
                'Pre-commit hook %s is a symlink that leads nowhere. Nothing identifies it, so it is left alone. '
                . 'Replace it with a working hook: %s hook:install --force. Or remove it by hand: rm %s',
                $hookPath,
                $this->runningBinaryLocator->hint(),
                $hookPath,
            ));
        }

        $target = $this->judge($hookPath, $output);
        $original = self::entry($hookPath);
        $content = self::read($hookPath);

        if (!PreCommitHook::isOurs($content)) {
            throw $this->refusal(\sprintf(
                'Pre-commit hook %s is not a Qualimetrix hook, so it is left alone. Remove it by hand if it is no longer wanted.',
                $hookPath,
            ));
        }

        self::assertSameEntry($hookPath, $original);
        if (!$target->sameAs($this->judge($hookPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook changed before removal');
        }
        [$removed, $reason] = self::attempt(static fn(): bool => unlink($hookPath));
        if (!$removed) {
            throw EnvironmentRefusal::aboutFile($hookPath, 'remove', $reason);
        }

        $output->writeln('<info>✓ Pre-commit hook removed</info>');
    }

    /** @param ?array<string|int, int> $backupEntry */
    private function restoreBackup(string $hookPath, OutputInterface $output, ?ResolvedTarget $backupTarget, ?array $backupEntry): void
    {
        $backupPath = $hookPath . '.backup';

        if ($backupTarget === null || $backupEntry === null) {
            $output->writeln('<comment>No backup found to restore</comment>');

            return;
        }

        $this->assertReadyToRestore($hookPath, $backupPath, $backupTarget, $backupEntry, $output);
        [$restored, $reason] = self::attempt(static fn(): bool => rename($backupPath, $hookPath));
        if (!$restored) {
            throw EnvironmentRefusal::aboutFile($backupPath, 'restore backup', $reason);
        }

        $output->writeln('<info>✓ Backup restored</info>');
    }

    /** @param array<string|int, int> $backupEntry */
    private function assertReadyToRestore(string $hookPath, string $backupPath, ResolvedTarget $backupTarget, array $backupEntry, OutputInterface $output): void
    {
        $destination = $this->judge($hookPath, $output);
        if ($destination->kind !== TargetKind::Absent || $destination->path?->value() !== $hookPath) {
            throw new FileTargetFailure(FileTargetFailureKind::Appeared, $hookPath, 'hook name appeared before backup restoration');
        }
        self::assertSameEntry($backupPath, $backupEntry);
        if (!$backupTarget->sameAs($this->judge($backupPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $backupPath, 'backup changed before restoration');
        }
        clearstatcache(true, $hookPath);
        [$current] = self::attempt(static fn() => lstat($hookPath));
        if ($current !== false) {
            throw new FileTargetFailure(FileTargetFailureKind::Appeared, $hookPath, 'hook name appeared before backup restoration');
        }
        if (!$destination->sameAs($this->judge($hookPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook destination changed before restoration');
        }
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
