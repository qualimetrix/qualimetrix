<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Hook;

use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Symfony\Component\Console\Output\OutputInterface;

/** Creates a guarded backup of an existing hook before installation. */
final class HookBackupTransaction
{
    /**
     * @return 'broken'|'ours'|'already'|'occupied'|'created'
     */
    public static function backUp(HookEntryAccess $files, string $hookPath, OutputInterface $output): string
    {
        if (!file_exists($hookPath)) {
            return self::missingHook($files, $hookPath, $output);
        }

        $target = $files->judge($hookPath, $output);
        $hookEntry = $files->entry($hookPath);
        $contents = $files->read($hookPath);
        if (PreCommitHook::isOurs($contents)) {
            return 'ours';
        }

        $backupPath = $hookPath . '.backup';
        $existing = self::existingBackup($files, $backupPath, $contents, $output);
        if ($existing !== null) {
            return $existing;
        }

        self::createBackup($files, $hookPath, $backupPath, $target, $hookEntry, $contents, $output);

        return 'created';
    }

    /** @return 'broken' */
    private static function missingHook(HookEntryAccess $files, string $hookPath, OutputInterface $output): string
    {
        if (!$files->danglingLink($hookPath, $output)) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook disappeared before backup');
        }

        return 'broken';
    }

    /** @return 'already'|'occupied'|null */
    private static function existingBackup(HookEntryAccess $files, string $backupPath, string $contents, OutputInterface $output): ?string
    {
        if (!file_exists($backupPath) && !is_link($backupPath)) {
            return null;
        }
        $files->judge($backupPath, $output);

        return $files->read($backupPath) === $contents ? 'already' : 'occupied';
    }

    /** @param array<string|int, int> $hookEntry */
    private static function createBackup(
        HookEntryAccess $files,
        string $hookPath,
        string $backupPath,
        ResolvedTarget $target,
        array $hookEntry,
        string $contents,
        OutputInterface $output,
    ): void {
        $sourcePath = $target->path?->value() ?? throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $hookPath, 'hook is not a regular file');
        $sourceEntry = $files->entry($sourcePath);
        $mode = $sourceEntry['mode'] & 07777;
        $backupTarget = $files->judge($backupPath, $output);
        $files->assertSameEntry($hookPath, $hookEntry);
        $files->assertSameEntry($sourcePath, $sourceEntry);
        if (!$target->sameAs($files->judge($hookPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook changed before backup');
        }
        FileReplacement::replace($backupTarget, $contents, $mode, NewName::Exclusive);
    }
}
