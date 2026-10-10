<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final class TargetPath
{
    public static function resolve(string $spelling, ?PrivateGroupMembership $membership = null): ResolvedTarget
    {
        $membership ??= NativePrivateGroupMembership::forProcess();
        $descriptor = self::schemeDescriptor($spelling);
        if ($descriptor !== null) {
            return new ResolvedTarget($spelling, TargetKind::Descriptor, null, $descriptor, null, new PathInspection([], []), $membership);
        }

        $path = self::pathSpelling($spelling);
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'cannot determine the working directory');
            }
            $path = $cwd . '/' . $path;
        }

        return (new PathWalk($spelling, $path, static fn(string $candidate): ?int => self::descriptorPrefix($candidate), $membership))->resolve();
    }

    /** Retain a newly created private directory's ownership while its facts remain unchanged. */
    public static function rememberCreatedDirectory(string $directory): void
    {
        PathWalk::rememberCreatedDirectory($directory);
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

}
