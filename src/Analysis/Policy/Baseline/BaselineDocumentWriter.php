<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use LogicException;
use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\HeldLock;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;

/**
 * Prepares and atomically replaces one baseline document under its sibling
 * lock. The prepared target carries the exact filesystem decision from the
 * caller to the locked replacement.
 */
final readonly class BaselineDocumentWriter
{
    public const float DEFAULT_LOCK_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private float $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS,
    ) {}

    /** @return array{target: ResolvedTarget, hash: ?string} */
    public static function snapshot(string $spelling): array
    {
        $target = TargetPath::resolve($spelling);
        self::assertDocumentTarget($target);
        self::assertWritableParent($target);

        if ($target->kind === TargetKind::Absent) {
            return ['target' => $target, 'hash' => null];
        }

        $claim = HeldTarget::claim($target);
        $claim->release();

        $path = $target->path?->value() ?? throw new LogicException('Regular baseline target has no path');
        [$hash, $warning] = self::attempt(static fn(): string|false => hash_file('sha256', $path));
        if ($hash === false) {
            throw new FileTargetFailure(
                FileTargetFailureKind::Unopenable,
                $target->spelling,
                'cannot read baseline destination',
                $warning,
            );
        }

        return ['target' => $target, 'hash' => $hash];
    }

    /** @throws BaselineConflictException if the prepared target no longer has the expected contents */
    public function replace(ResolvedTarget $target, string $contents, ?string $expectedHash): void
    {
        self::assertDocumentTarget($target);
        $path = $target->path?->value() ?? throw new LogicException('Baseline target has no path');
        $lock = HeldLock::acquire(TargetPath::resolve($path . '.lock'), $this->lockTimeoutSeconds);

        try {
            self::assertExpectation($target, $expectedHash);
            FileReplacement::replace(
                $target,
                $contents,
                mode: null,
                newName: $target->kind === TargetKind::Absent ? NewName::Exclusive : NewName::LastWriterWins,
            );
        } finally {
            $lock->release();
        }
    }

    private static function assertDocumentTarget(ResolvedTarget $target): void
    {
        if ($target->kind === TargetKind::Regular || $target->kind === TargetKind::Absent) {
            return;
        }

        throw new FileTargetFailure(
            $target->kind === TargetKind::Descriptor
                ? FileTargetFailureKind::ForeignDescriptor
                : FileTargetFailureKind::Unopenable,
            $target->spelling,
            'a baseline document requires a regular file path',
        );
    }

    private static function assertWritableParent(ResolvedTarget $target): void
    {
        $path = $target->path?->value() ?? throw new LogicException('Baseline target has no path');
        $parent = \dirname($path);
        clearstatcache(true, $parent);

        if (!is_dir($parent)) {
            throw new FileTargetFailure(
                FileTargetFailureKind::DirectoryMissing,
                $target->spelling,
                'the parent directory is missing',
                $parent,
            );
        }
        if (!is_writable($parent) || !is_executable($parent)) {
            throw new FileTargetFailure(
                FileTargetFailureKind::Unopenable,
                $target->spelling,
                'the parent directory cannot publish a sibling replacement',
                $parent,
            );
        }
    }

    private static function assertExpectation(ResolvedTarget $target, ?string $expectedHash): void
    {
        $now = TargetPath::resolve($target->spelling);
        if ($target->kind === TargetKind::Absent) {
            if ($now->kind !== TargetKind::Absent) {
                throw new BaselineConflictException(\sprintf(
                    'Baseline file %s appeared since it was read as absent; refusing to overwrite. '
                    . 'Re-run the command to pick up the current file.',
                    $target->spelling,
                ));
            }

            return;
        }

        if ($now->kind !== TargetKind::Regular) {
            throw new BaselineConflictException(\sprintf(
                'Baseline file %s no longer exists; refusing to recreate it from a stale reading. '
                . 'Regenerate the baseline if its removal was intended.',
                $target->spelling,
            ));
        }
        if (!$target->sameAs($now)) {
            throw new BaselineConflictException(\sprintf(
                'Baseline file %s changed since it was read; refusing to overwrite. '
                . 'Re-run the command to pick up the current file.',
                $target->spelling,
            ));
        }
        if ($expectedHash === null) {
            return;
        }

        $path = $now->path?->value() ?? throw new LogicException('Regular baseline target has no path');
        [$hash] = self::attempt(static fn(): string|false => hash_file('sha256', $path));
        if ($hash !== $expectedHash) {
            throw new BaselineConflictException(\sprintf(
                'Baseline file %s changed since it was read; refusing to overwrite. '
                . 'Re-run the command to pick up the current file.',
                $target->spelling,
            ));
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
