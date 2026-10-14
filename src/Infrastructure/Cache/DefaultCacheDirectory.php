<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\Path\AbsolutePath;

final class DefaultCacheDirectory
{
    /** @param array<string, string> $environment */
    public static function for(array $environment, string $os): ?AbsolutePath
    {
        $xdg = $environment['XDG_CACHE_HOME'] ?? '';
        if (self::absolute($xdg)) {
            return AbsolutePath::fromString(rtrim($xdg, '/\\') . '/qmx');
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

        return AbsolutePath::fromString(rtrim($base, '/\\') . $suffix);
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }
}
