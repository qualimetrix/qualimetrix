<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Closure;
use Qualimetrix\Core\Path\AbsolutePath;

final class PathWalk
{
    /** @var list<string> */
    private array $todo;

    /** @var list<string> */
    private array $parts = [];

    private PathInspection $inspection;
    private int $hops = 0;

    /** @param Closure(string): ?int $descriptorAt */
    public function __construct(
        private readonly string $spelling,
        string $absolutePath,
        private readonly Closure $descriptorAt,
        private readonly PrivateGroupMembership $membership,
    ) {
        $this->todo = explode('/', ltrim($absolutePath, '/'));
        $this->inspection = new PathInspection([], []);
    }

    public function resolve(): ResolvedTarget
    {
        while ($this->todo !== []) {
            $part = array_shift($this->todo);
            if ($this->skipComponent($part)) {
                continue;
            }

            $candidate = '/' . implode('/', [...$this->parts, $part]);
            $descriptor = $this->descriptorTarget($candidate);
            if ($descriptor !== null) {
                return $descriptor;
            }

            clearstatcache(true, $candidate);
            [$entry, $warning] = NativeCall::attempt(static fn() => lstat($candidate));
            if ($entry === false) {
                return $this->absentTarget($candidate, $part, $warning ?? '');
            }

            [$parent, $control, $effectiveUid] = $this->entryControl($entry);
            $type = $entry['mode'] & 0170000;
            if ($type === 0120000) {
                $this->followLink($candidate, $entry, $control);
                continue;
            }
            if (self::hasRemainingComponents($this->todo)) {
                $this->enterDirectory($candidate, $part, $type, $entry, $control, $parent, $effectiveUid);
                continue;
            }

            return $this->finalTarget($candidate, $type, $entry, $control, $parent, $effectiveUid);
        }

        throw new FileTargetFailure(FileTargetFailureKind::Directory, $this->spelling, 'target is a directory');
    }

    private function skipComponent(string $part): bool
    {
        if ($part === '' || $part === '.') {
            return true;
        }
        if ($part === '..') {
            array_pop($this->parts);

            return true;
        }

        return false;
    }

    private function descriptorTarget(string $candidate): ?ResolvedTarget
    {
        $descriptor = ($this->descriptorAt)($candidate);
        if ($descriptor === null) {
            return null;
        }
        if ($descriptor < 0) {
            throw new FileTargetFailure(FileTargetFailureKind::ForeignDescriptor, $this->spelling, 'descriptor belongs to another process');
        }
        if (self::hasRemainingComponents($this->todo)) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->spelling, 'a descriptor cannot have path components after it');
        }

        return new ResolvedTarget($this->spelling, TargetKind::Descriptor, null, $descriptor, null, $this->inspection, $this->membership);
    }

    private function absentTarget(string $candidate, string $part, string $warning): ResolvedTarget
    {
        $parent = '/' . implode('/', $this->parts);
        (new PathAbsenceProof($this->spelling, $candidate, $part, $warning))->assertFinalAbsent($this->todo, $parent);

        return new ResolvedTarget($this->spelling, TargetKind::Absent, AbsolutePath::fromString($candidate), null, null, $this->inspection, $this->membership);
    }

    /**
     * @param array<string|int, int> $entry
     *
     * @return array{string, EntryControl, int}
     */
    private function entryControl(array $entry): array
    {
        $parent = '/' . implode('/', $this->parts);
        [$parentStat] = NativeCall::attempt(static fn() => lstat($parent));
        if ($parentStat === false) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $this->spelling, 'a parent directory changed during inspection', $parent);
        }

        $effectiveUid = $parentStat['uid'] === 0 && ($parentStat['mode'] & 0022) === 0
            ? 0
            : ProcessOwner::effectiveUid($parent);
        $control = EntryControl::of(DirectoryFacts::fromStat($parentStat), EntryFacts::fromStat($entry), $effectiveUid, $this->membership);

        return [$parent, $control, $effectiveUid];
    }

    /** @param array<string|int, int> $entry */
    private function followLink(string $candidate, array $entry, EntryControl $control): void
    {
        [$link] = NativeCall::attempt(static fn() => readlink($candidate));
        if ($link === false) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $this->spelling, 'a symbolic link changed during inspection', $candidate);
        }
        if ($control->placeableByOthers) {
            throw new FileTargetFailure(FileTargetFailureKind::ExposedLink, $this->spelling, 'a symbolic link can be placed by another user', $candidate . ' -> ' . $link . '; ' . ($control->changedBy ?? 'unknown'));
        }
        if (++$this->hops > 40) {
            throw new FileTargetFailure(FileTargetFailureKind::LinkLoop, $this->spelling, 'too many symbolic links', $candidate);
        }
        clearstatcache(true, $candidate);
        [$after] = NativeCall::attempt(static fn() => lstat($candidate));
        [$afterLink] = NativeCall::attempt(static fn() => readlink($candidate));
        if ($after === false || !FileIdentity::fromStat($entry)->sameAs(FileIdentity::fromStat($after)) || $afterLink !== $link) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $this->spelling, 'a symbolic link changed during inspection', $candidate);
        }

        $this->todo = [...explode('/', ltrim($link, '/')), ...$this->todo];
        if (str_starts_with($link, '/')) {
            $this->parts = [];
        }
    }

    /** @param array<string|int, int> $entry */
    private function enterDirectory(string $candidate, string $part, int $type, array $entry, EntryControl $control, string $parent, int $effectiveUid): void
    {
        if ($type !== 0040000) {
            throw new FileTargetFailure(FileTargetFailureKind::DirectoryMissing, $this->spelling, 'a path component is not a directory', $candidate);
        }
        $this->parts[] = $part;
        $this->inspection = $this->inspection->withDirectory($candidate, FileIdentity::fromStat($entry), $control, $parent, $effectiveUid);
    }

    /** @param array<string|int, int> $entry */
    private function finalTarget(string $candidate, int $type, array $entry, EntryControl $control, string $parent, int $effectiveUid): ResolvedTarget
    {
        $kind = $this->terminalKind($candidate, $type);
        $this->inspection = $this->inspection->withTerminalEntry($type, $control, $parent, $effectiveUid);

        return new ResolvedTarget(
            $this->spelling,
            $kind,
            AbsolutePath::fromString($candidate),
            null,
            FileIdentity::fromStat($entry),
            $this->inspection,
            $this->membership,
        );
    }

    private function terminalKind(string $candidate, int $type): TargetKind
    {
        if ($type === 0040000) {
            throw new FileTargetFailure(FileTargetFailureKind::Directory, $this->spelling, 'target is a directory', $candidate);
        }
        if (!\in_array($type, [0100000, 0010000, 0020000, 0060000], true)) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $this->spelling, 'unsupported filesystem entry', $candidate);
        }

        return $type === 0100000 ? TargetKind::Regular : TargetKind::Stream;
    }

    /** @param list<string> $parts */
    private static function hasRemainingComponents(array $parts): bool
    {
        return $parts !== [];
    }
}
