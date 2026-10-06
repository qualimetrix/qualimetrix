<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Hook;

use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Symfony\Component\Console\Output\OutputInterface;

/** Shared guarded access to hook entries and their exposure warnings. */
final class HookEntryAccess
{
    /** @var array<string, true> */
    private array $reportedExposure = [];

    public function __construct(private readonly ErrorStream $errorStream) {}

    public function begin(): void
    {
        $this->reportedExposure = [];
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
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return array{T, string}
     */
    public static function attempt(callable $operation): array
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
