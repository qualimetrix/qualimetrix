<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use InvalidArgumentException;
use Qualimetrix\Core\Path\AbsolutePath;

final class DefaultCacheDirectory
{
    /** @param array<string, string> $environment */
    public static function for(array $environment, string $os): ?AbsolutePath
    {
        $xdg = $environment['XDG_CACHE_HOME'] ?? '';
        if (self::absolute($xdg)) {
            return self::fromBase($xdg, '/qmx');
        }
        $base = $os === 'Windows' ? ($environment['LOCALAPPDATA'] ?? '') : ($environment['HOME'] ?? '');
        if (!self::absolute($base)) {
            return null;
        }
        $suffix = match ($os) {
            'Windows' => '/qmx',
            'Darwin' => '/Library/Caches/qmx',
            default => '/.cache/qmx',
        };

        return self::fromBase($base, $suffix);
    }

    private static function fromBase(string $base, string $suffix): ?AbsolutePath
    {
        try {
            return AbsolutePath::fromString(rtrim($base, '/\\') . $suffix);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }
}
