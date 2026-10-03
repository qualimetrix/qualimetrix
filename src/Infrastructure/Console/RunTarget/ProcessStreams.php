<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use Qualimetrix\Core\FileTarget\FileIdentity;

/** Inspects descriptors already held by this process without opening their paths. */
final class ProcessStreams
{
    public static function identity(int $fd): ?FileIdentity
    {
        $handle = self::duplicate($fd);
        if ($handle === null) {
            return null;
        }

        try {
            $stat = fstat($handle);

            return $stat === false ? null : FileIdentity::fromStat($stat);
        } finally {
            fclose($handle);
        }
    }

    public static function isWritable(int $fd): ?bool
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        $info = self::withoutWarning(static fn(): string|false => file_get_contents('/proc/self/fdinfo/' . $fd));
        if (!\is_string($info) || preg_match('/^flags:\s*([0-7]+)/m', $info, $match) !== 1) {
            return null;
        }

        return (octdec($match[1]) & 0o3) !== 0;
    }

    /** @return resource|null */
    private static function duplicate(int $fd): mixed
    {
        if ($fd < 0) {
            return null;
        }

        $handle = self::withoutWarning(static fn() => fopen('php://fd/' . $fd, 'r'));

        return \is_resource($handle) ? $handle : null;
    }

    private static function withoutWarning(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
