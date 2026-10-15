<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Qualimetrix\Core\Path\AbsolutePath;

final class WorkerFatalNotes
{
    private static ?self $registered = null;
    private ?string $reserve;
    private ?AbsolutePath $file = null;

    private function __construct(private readonly string $nonce)
    {
        $this->reserve = str_repeat(' ', 256 * 1024);
    }

    public static function forWorker(string $nonce): void
    {
        if (self::$registered !== null) {
            return;
        }
        $notes = self::$registered = new self($nonce);
        ini_set('display_errors', '0');
        // The parent reads the private PHP log if the shutdown callback cannot run.
        ini_set('log_errors', '1');
        set_error_handler(static function (int $level, string $message, string $file, int $line) use ($notes): bool {
            if ((error_reporting() & $level) === 0) {
                return true;
            }
            $severity = match ($level) {
                \E_NOTICE, \E_USER_NOTICE => 'Notice',
                \E_DEPRECATED, \E_USER_DEPRECATED => 'Deprecated',
                default => 'Warning',
            };
            $notes->note(['kind' => 'diagnostic', 'message' => $message, 'file' => $file, 'line' => $line, 'severity' => $severity]);
            return true;
        }, \E_WARNING | \E_NOTICE | \E_USER_WARNING | \E_USER_NOTICE | \E_DEPRECATED | \E_USER_DEPRECATED);
        register_shutdown_function($notes->onShutdown(...));
    }

    public static function workingOnFile(?AbsolutePath $file): void
    {
        if (self::$registered === null) {
            return;
        }
        $error = error_get_last();
        if ($file === null && $error !== null && \in_array($error['type'], [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR], true)) {
            return;
        }
        self::$registered->file = $file;
    }

    /** @param array<string, int|string> $note */
    private function note(array $note): void
    {
        fwrite(\STDERR, 'QMX-WORKER-NOTE ' . $this->nonce . ' ' . base64_encode(json_encode($note, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE)) . "\n");
    }

    private function onShutdown(): void
    {
        $error = error_get_last();
        if ($this->reserve === null) {
            return;
        }
        $this->reserve = null;
        if ($error === null || !\in_array($error['type'], [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR], true) || $this->file === null) {
            return;
        }
        $this->note([
            'kind' => 'death',
            'file' => $this->file->value(),
            'message' => $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'],
            'limit' => (string) \ini_get('memory_limit'),
        ]);
    }
}
