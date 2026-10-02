<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Qualimetrix\Core\Path\AbsolutePath;

final class TargetPath
{
    public static function resolve(string $spelling): ResolvedTarget
    {
        $descriptor = self::schemeDescriptor($spelling);
        if ($descriptor !== null) {
            return new ResolvedTarget($spelling, TargetKind::Descriptor, null, $descriptor, null, [], []);
        }

        $path = self::pathSpelling($spelling);
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'cannot determine the working directory');
            }
            $path = $cwd . '/' . $path;
        }

        $todo = explode('/', ltrim($path, '/'));
        $parts = [];
        $directories = [];
        $exposure = [];
        $streamExposed = false;
        $hops = 0;

        while ($todo !== []) {
            $part = array_shift($todo);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $candidate = '/' . implode('/', [...$parts, $part]);
            $stop = self::descriptorPrefix($candidate);
            if ($stop !== null) {
                if ($stop < 0) {
                    throw new FileTargetFailure(FileTargetFailureKind::ForeignDescriptor, $spelling, 'descriptor belongs to another process');
                }
                if (self::hasRemainingComponents($todo)) {
                    throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'a descriptor cannot have path components after it');
                }

                return new ResolvedTarget($spelling, TargetKind::Descriptor, null, $stop, null, $directories, $exposure);
            }

            clearstatcache(true, $candidate);
            $entry = @lstat($candidate);
            if ($entry === false) {
                $failure = error_get_last()['message'] ?? '';
                if (str_contains($failure, 'File name too long') || \strlen($part) > 255) {
                    throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'File name too long', $candidate);
                }
                if (str_contains($failure, 'Permission denied')) {
                    throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'cannot inspect target', $failure);
                }
                if (self::hasRemainingComponents($todo)) {
                    throw new FileTargetFailure(FileTargetFailureKind::DirectoryMissing, $spelling, 'a parent directory is missing', $candidate);
                }
                $parent = '/' . implode('/', $parts);
                if (@is_dir($parent) !== true) {
                    throw new FileTargetFailure(FileTargetFailureKind::DirectoryMissing, $spelling, 'the parent directory is missing', $parent);
                }

                return new ResolvedTarget($spelling, TargetKind::Absent, AbsolutePath::fromString($candidate), null, null, $directories, $exposure);
            }

            $parent = '/' . implode('/', $parts);
            $parentStat = @lstat($parent);
            if ($parentStat === false) {
                throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $spelling, 'a parent directory changed during inspection', $parent);
            }

            $effectiveUid = $parentStat['uid'] === 0 && ($parentStat['mode'] & 0022) === 0
                ? 0
                : ProcessOwner::effectiveUid($parent);
            $control = EntryControl::of(DirectoryFacts::fromStat($parentStat), EntryFacts::fromStat($entry), $effectiveUid);
            $type = $entry['mode'] & 0170000;
            if ($type === 0120000) {
                $link = @readlink($candidate);
                if ($link === false) {
                    throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $spelling, 'a symbolic link changed during inspection', $candidate);
                }
                if ($control->placeableByOthers) {
                    throw new FileTargetFailure(FileTargetFailureKind::ExposedLink, $spelling, 'a symbolic link can be placed by another user', $candidate . ' -> ' . $link . '; ' . ($control->changedBy ?? 'unknown'));
                }
                if (++$hops > 40) {
                    throw new FileTargetFailure(FileTargetFailureKind::LinkLoop, $spelling, 'too many symbolic links', $candidate);
                }
                clearstatcache(true, $candidate);
                $after = @lstat($candidate);
                if ($after === false || !FileIdentity::fromStat($entry)->sameAs(FileIdentity::fromStat($after)) || @readlink($candidate) !== $link) {
                    throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $spelling, 'a symbolic link changed during inspection', $candidate);
                }

                $todo = [...explode('/', ltrim($link, '/')), ...$todo];
                if (str_starts_with($link, '/')) {
                    $parts = [];
                }
                continue;
            }

            if (self::hasRemainingComponents($todo)) {
                if ($type !== 0040000) {
                    throw new FileTargetFailure(FileTargetFailureKind::DirectoryMissing, $spelling, 'a path component is not a directory', $candidate);
                }
                $parts[] = $part;
                $directories[] = ['path' => $candidate, 'identity' => FileIdentity::fromStat($entry)];
                if ($control->swappableByOthers) {
                    $streamExposed = true;
                    $trace = $control->forTrace($parent, $effectiveUid === 0);
                    if ($trace !== null) {
                        $exposure[] = $trace;
                    }
                }
                continue;
            }

            if ($type === 0040000) {
                throw new FileTargetFailure(FileTargetFailureKind::Directory, $spelling, 'target is a directory', $candidate);
            }
            if ($type !== 0100000 && $type !== 0010000 && $type !== 0020000 && $type !== 0060000) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'unsupported filesystem entry', $candidate);
            }

            if ($type === 0100000 && $control->placeableByOthers) {
                $trace = $control->forTrace($parent, $effectiveUid === 0);
                if ($trace !== null) {
                    $exposure[] = $trace;
                }
            }
            $streamExposed = $streamExposed || ($type !== 0100000 && $control->swappableByOthers);

            return new ResolvedTarget($spelling, $type === 0100000 ? TargetKind::Regular : TargetKind::Stream, AbsolutePath::fromString($candidate), null, FileIdentity::fromStat($entry), $directories, $exposure, $streamExposed);
        }

        throw new FileTargetFailure(FileTargetFailureKind::Directory, $spelling, 'target is a directory');
    }

    private static function schemeDescriptor(string $spelling): ?int
    {
        if ($spelling === 'php://stdout') {
            return 1;
        }
        if ($spelling === 'php://stderr') {
            return 2;
        }
        if (preg_match('~^php://fd/([0-9]+)$~', $spelling, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private static function pathSpelling(string $spelling): string
    {
        if (str_starts_with($spelling, 'file://')) {
            $tail = substr($spelling, 7);
            if (str_starts_with($tail, 'localhost/')) {
                $tail = substr($tail, 9);
            }
            if (str_starts_with($tail, '/')) {
                return $tail;
            }
        } elseif (!str_contains($spelling, '://')) {
            return $spelling;
        }

        throw new FileTargetFailure(FileTargetFailureKind::UnsupportedScheme, $spelling, 'unsupported target scheme');
    }

    private static function descriptorPrefix(string $path): ?int
    {
        if (preg_match('~^/dev/(?:fd/([0-9]+)|stdout|stderr|stdin)$~', $path, $match) === 1) {
            return isset($match[1]) ? (int) $match[1] : match ($path) {
                '/dev/stdin' => 0,
                '/dev/stdout' => 1,
                default => 2,
            };
        }

        if (preg_match('~^/proc/([^/]+)(?:/task/[0-9]+)?/fd/([0-9]+)$~', $path, $match) === 1) {
            if ($match[1] !== 'self' && $match[1] !== 'thread-self' && $match[1] !== (string) getmypid()) {
                return -1;
            }

            return (int) $match[2];
        }

        return null;
    }

    /** @param list<string> $parts */
    private static function hasRemainingComponents(array $parts): bool
    {
        foreach ($parts as $part) {
            if ($part !== '' && $part !== '.') {
                return true;
            }
        }

        return false;
    }
}
