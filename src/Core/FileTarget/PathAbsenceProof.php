<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

/** Verifies a missing final name through the same searchable parent. */
final readonly class PathAbsenceProof
{
    public function __construct(
        private string $spelling,
        private string $candidate,
        private string $part,
        private string $warning,
    ) {}

    /** @param list<string> $remaining */
    public function assertFinalAbsent(array $remaining, string $parent): void
    {
        $this->assertUsableParent($parent);
        $this->assertLstatFailure($remaining);
    }

    /** @param list<string> $remaining */
    private function assertLstatFailure(array $remaining): void
    {
        if (str_contains($this->warning, 'File name too long') || \strlen($this->part) > 255) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->spelling, 'File name too long', $this->candidate);
        }
        if (str_contains($this->warning, 'Permission denied')) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->spelling, 'cannot inspect target', $this->warning);
        }
        if (!self::knownAbsenceWarning($this->warning)) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->spelling, 'cannot inspect target', $this->candidate . ($this->warning === '' ? '' : ': ' . $this->warning));
        }
        if ($remaining !== []) {
            throw new FileTargetFailure(FileTargetFailureKind::DirectoryMissing, $this->spelling, 'a parent directory is missing', $this->candidate);
        }
    }

    private static function knownAbsenceWarning(string $warning): bool
    {
        return $warning !== ''
            && (str_contains($warning, 'No such file or directory')
                || str_contains($warning, 'Not a directory')
                || str_contains($warning, 'Lstat failed for'));
    }

    private function assertUsableParent(string $parent): void
    {
        [$isDirectory] = NativeCall::attempt(static fn() => is_dir($parent));
        if ($isDirectory !== true) {
            throw new FileTargetFailure(FileTargetFailureKind::DirectoryMissing, $this->spelling, 'the parent directory is missing', $parent);
        }
        [$searchable, $searchWarning] = NativeCall::attempt(static fn() => is_executable($parent));
        if ($searchable !== true) {
            $detail = $parent . ': ' . $this->warning;
            if ($searchWarning !== null) {
                $detail .= '; ' . $searchWarning;
            }

            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->spelling, 'cannot inspect target through a non-searchable parent', $detail);
        }
    }
}
