<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\TargetPath;

final class CacheLayout
{
    public const string TAG = "Signature: 8a477f597d28d172789f06886806bc55\n# This directory contains qmx AST cache entries.\n";

    public static function versionDirectory(string $root): string
    {
        return $root . '/ast-v' . AstEntryCodec::FORMAT_VERSION;
    }

    public static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            $parent = \dirname($directory);
            if ($parent !== $directory) {
                self::ensureDirectory($parent);
            }
            if (@mkdir($directory, 0700)) {
                TargetPath::rememberCreatedDirectory($directory);
            } elseif (!is_dir($directory)) {
                throw CacheWriteException::failedToCreateDirectory($directory);
            }
        }
    }

    public static function write(string $path, string $bytes): void
    {
        FileReplacement::replace(TargetPath::resolve($path), $bytes, null, NewName::LastWriterWins);
    }

    public static function ensureVersion(string $root): void
    {
        $directory = self::versionDirectory($root);
        self::ensureDirectory($directory);
        if (!is_file($directory . '/CACHEDIR.TAG')) {
            self::write($directory . '/CACHEDIR.TAG', self::TAG);
        }
    }

    /** @return ?list<string> */
    public static function versions(string $root): ?array
    {
        if (!is_dir($root)) {
            return file_exists($root) || is_link($root) ? null : [];
        }
        $names = @scandir($root);
        if ($names === false) {
            return null;
        }
        $versions = [];
        foreach ($names as $name) {
            $path = $root . '/' . $name;
            if (preg_match('/^ast-v[0-9]+$/D', $name) === 1 && is_dir($path) && !is_link($path)) {
                $versions[] = $path;
            }
        }

        return $versions;
    }

    public static function entryName(string $name): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $name) === 1;
    }
}
