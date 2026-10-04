<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

final class OutOfMemoryHint
{
    private static ?string $reserve = null;
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$reserve = str_repeat(' ', 16 * 1024);
        self::$registered = true;
        ini_set('display_errors', 'stderr');
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null
                || $error['type'] !== \E_ERROR
                || !str_starts_with($error['message'], 'Allowed memory size of ')) {
                return;
            }

            if (self::$reserve === null) {
                return;
            }
            self::$reserve = null;
            fwrite(\STDERR, \sprintf(
                "Qualimetrix ran out of memory at %s:%d (memory_limit=%s); raise --memory-limit or memory_limit in qmx.yaml.\n",
                $error['file'],
                $error['line'],
                \ini_get('memory_limit'),
            ));
            exit(4);
        });
    }
}
