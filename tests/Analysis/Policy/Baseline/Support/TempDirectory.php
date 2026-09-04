<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Support;

/**
 * Recursive removal of a test's scratch directory.
 *
 * Every baseline command test needs one, and a baseline directory holds more
 * than the file under test — {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineWriter}
 * leaves a `.lock` sibling behind by design, so a teardown that unlinked one
 * known filename would leak.
 */
final class TempDirectory
{
    public static function create(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                self::remove($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
