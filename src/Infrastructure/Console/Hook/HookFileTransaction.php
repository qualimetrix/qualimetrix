<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Hook;

use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Symfony\Component\Console\Output\OutputInterface;

/** Judges hook file identities and performs their replacement, removal and restoration. */
final class HookFileTransaction
{
    /** @var array<string, true> */
    private array $reportedExposure = [];

    public function __construct(private readonly ErrorStream $errorStream) {}

    public function begin(): void
    {
        $this->reportedExposure = [];
    }

    public function exists(string $path): bool
    {
        return is_link($path) || file_exists($path);
    }

    public function danglingLink(string $path, OutputInterface $output): bool
    {
        if (!is_link($path) || file_exists($path)) {
            return false;
        }
        try {
            $target = $this->judge($path, $output);
        } catch (FileTargetFailure $failure) {
            if ($failure->kind === FileTargetFailureKind::DirectoryMissing) {
                return true;
            }

            throw $failure;
        }
        if ($target->kind === TargetKind::Absent) {
            return true;
        }

        throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $path, 'hook link changed during inspection');
    }

    public function judge(string $path, OutputInterface $output): ResolvedTarget
    {
        $target = TargetPath::resolve($path);
        if ($target->exposure !== [] && !isset($this->reportedExposure[$path])) {
            $exposure = $target->exposure[0];
            $this->errorStream->write($output, \sprintf('Warning: Hook target %s can be changed through %s by %s.', $path, $exposure->directory, $exposure->changedBy));
            $this->reportedExposure[$path] = true;
        }

        return $target;
    }

    public function read(string $path): string
    {
        [$contents, $reason] = self::attempt(static fn() => file_get_contents($path));
        if ($contents === false) {
            throw EnvironmentRefusal::aboutFile($path, 'read', $reason);
        }

        return $contents;
    }

    /** @return array<string|int, int> */
    public function entry(string $path): array
    {
        clearstatcache(true, $path);
        [$entry, $reason] = self::attempt(static fn() => lstat($path));
        if ($entry === false) {
            throw EnvironmentRefusal::aboutFile($path, 'inspect', $reason);
        }

        return $entry;
    }

    /** @param array<string|int, int> $original */
    public function assertSameEntry(string $path, array $original): void
    {
        clearstatcache(true, $path);
        [$now] = self::attempt(static fn() => lstat($path));
        if ($now === false || !FileIdentity::fromStat($original)->sameAs(FileIdentity::fromStat($now))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $path, 'hook entry changed before operation');
        }
    }

    /**
     * @return 'broken'|'ours'|'already'|'occupied'|'created'
     */
    public function backUp(string $hookPath, OutputInterface $output): string
    {
        if (!file_exists($hookPath)) {
            if (!$this->danglingLink($hookPath, $output)) {
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook disappeared before backup');
            }

            return 'broken';
        }

        $target = $this->judge($hookPath, $output);
        $hookEntry = $this->entry($hookPath);
        $contents = $this->read($hookPath);
        if (PreCommitHook::isOurs($contents)) {
            return 'ours';
        }

        $backupPath = $hookPath . '.backup';
        if (file_exists($backupPath) || is_link($backupPath)) {
            $this->judge($backupPath, $output);

            return $this->read($backupPath) === $contents ? 'already' : 'occupied';
        }

        $sourcePath = $target->path?->value() ?? throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $hookPath, 'hook is not a regular file');
        $sourceEntry = $this->entry($sourcePath);
        $mode = $sourceEntry['mode'] & 07777;
        $backupTarget = $this->judge($backupPath, $output);
        $this->assertSameEntry($hookPath, $hookEntry);
        $this->assertSameEntry($sourcePath, $sourceEntry);
        if (!$target->sameAs($this->judge($hookPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook changed before backup');
        }
        FileReplacement::replace($backupTarget, $contents, $mode, NewName::Exclusive);

        return 'created';
    }

    public function removeLink(string $path): void
    {
        if (!is_link($path)) {
            return;
        }

        $entry = $this->entry($path);
        $this->assertSameEntry($path, $entry);
        [$removed, $reason] = self::attempt(static fn(): bool => unlink($path));
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
        [$removed, $reason] = self::attempt(static fn(): bool => unlink($path));
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
        [$current] = self::attempt(static fn() => lstat($hookPath));
        if ($current !== false) {
            throw new FileTargetFailure(FileTargetFailureKind::Appeared, $hookPath, 'hook name appeared before backup restoration');
        }
        if (!$destination->sameAs($this->judge($hookPath, $output))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $hookPath, 'hook destination changed before restoration');
        }
        [$restored, $reason] = self::attempt(static fn(): bool => rename($backupPath, $hookPath));
        if (!$restored) {
            throw EnvironmentRefusal::aboutFile($backupPath, 'restore backup', $reason);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return array{T, string}
     */
    private static function attempt(callable $operation): array
    {
        $reason = 'unknown reason';
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            $reason = preg_match('~^[a-z_]+\([^)]*\): (.+)$~s', $message, $match) === 1 ? $match[1] : $message;

            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $reason];
    }
}
