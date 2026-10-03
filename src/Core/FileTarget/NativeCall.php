<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

final class NativeCall
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return array{T, ?string}
     */
    public static function attempt(callable $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
