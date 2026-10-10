<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Hook;

use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Symfony\Component\Console\Output\OutputInterface;

/** Judges hook file identities and performs their replacement, removal and restoration. */
final class HookFileTransaction
{
    private readonly HookEntryAccess $access;

    public function __construct(ErrorStream $errorStream)
    {
        $this->access = new HookEntryAccess($errorStream);
    }

    public function begin(): void
    {
        $this->access->begin();
    }

    public function exists(string $path): bool
    {
        return is_link($path) || file_exists($path);
    }

    public function danglingLink(string $path, OutputInterface $output): bool
    {
        return $this->access->danglingLink($path, $output);
    }

    public function judge(string $path, OutputInterface $output): ResolvedTarget
    {
        return $this->access->judge($path, $output);
    }

    public function read(string $path): string
    {
        return $this->access->read($path);
    }

    /** @return array<string|int, int> */
    public function entry(string $path): array
    {
        return $this->access->entry($path);
    }

    /** @param array<string|int, int> $original */
    public function assertSameEntry(string $path, array $original): void
    {
        $this->access->assertSameEntry($path, $original);
    }

    /** @return 'broken'|'ours'|'already'|'occupied'|'created' */
    public function backUp(string $hookPath, OutputInterface $output): string
    {
        return HookBackupTransaction::backUp($this->access, $hookPath, $output);
    }

    public function removeLink(string $path): void
    {
        if (!is_link($path)) {
            return;
        }

        $entry = $this->entry($path);
        $this->assertSameEntry($path, $entry);
        [$removed, $reason] = HookEntryAccess::attempt(static fn(): bool => unlink($path));
        if (!$removed) {
            throw EnvironmentRefusal::aboutFile($path, 'remove', $reason);
        }
    }

    public function write(string $path, string $contents, OutputInterface $output): void
    {
        $target = $this->judge($path, $output);
        if ($target->path?->value() !== $path) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $path, 'hook name changed before installation');
        }

        FileReplacement::replace(
            $target,
            $contents,
            0755,
            $target->kind === TargetKind::Absent ? NewName::Exclusive : NewName::LastWriterWins,
        );
    }

    /** @return array{ResolvedTarget, array<string|int, int>}|null */
    public function backupToRestore(string $hookPath, OutputInterface $output): ?array
    {
        $backupPath = $hookPath . '.backup';
        if (!$this->exists($backupPath)) {
            return null;
        }

        $target = $this->judge($backupPath, $output);
        if (is_link($backupPath) || $target->kind !== TargetKind::Regular || $target->path?->value() !== $backupPath) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $backupPath, 'backup must be a regular file in the hooks directory');
        }

        return [$target, $this->entry($backupPath)];
    }

    /** @return 'dangling'|'foreign'|'removed' */
    public function removeOwnedHook(string $path, OutputInterface $output): string
    {
        if ($this->danglingLink($path, $output)) {
            return 'dangling';
        }

        $target = $this->judge($path, $output);
        $original = $this->entry($path);
        if (!PreCommitHook::isOurs($this->read($path))) {
            return 'foreign';
        }

        $this->assertSameEntry($path, $original);
        if (!$target->sameAs($this->judge($path, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $path, 'hook changed before removal');
        }
        [$removed, $reason] = HookEntryAccess::attempt(static fn(): bool => unlink($path));
        if (!$removed) {
            throw EnvironmentRefusal::aboutFile($path, 'remove', $reason);
        }

        return 'removed';
    }

    /** @param array{ResolvedTarget, array<string|int, int>} $backup */
    public function restore(string $hookPath, array $backup, OutputInterface $output): void
    {
        $backupPath = $hookPath . '.backup';
        [$backupTarget, $backupEntry] = $backup;
        $destination = $this->judge($hookPath, $output);
        if ($destination->kind !== TargetKind::Absent || $destination->path?->value() !== $hookPath) {
            throw new FileTargetFailure(FileTargetFailureKind::Appeared, $hookPath, 'hook name appeared before backup restoration');
        }
        $this->assertSameEntry($backupPath, $backupEntry);
        if (!$backupTarget->sameAs($this->judge($backupPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $backupPath, 'backup changed before restoration');
        }
        clearstatcache(true, $hookPath);
        [$current] = HookEntryAccess::attempt(static fn() => lstat($hookPath));
        if ($current !== false) {
            throw new FileTargetFailure(FileTargetFailureKind::Appeared, $hookPath, 'hook name appeared before backup restoration');
        }
        if (!$destination->sameAs($this->judge($hookPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook destination changed before restoration');
        }
        [$restored, $reason] = HookEntryAccess::attempt(static fn(): bool => rename($backupPath, $hookPath));
        if (!$restored) {
            throw EnvironmentRefusal::aboutFile($backupPath, 'restore backup', $reason);
        }
    }

}
