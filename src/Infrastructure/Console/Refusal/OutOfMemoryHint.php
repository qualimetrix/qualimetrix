<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

use Closure;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class OutOfMemoryHint
{
    private static ?self $registered = null;
    private ?string $reserve;
    private ?string $workerNonce = null;
    private ?string $workerFile = null;
    private ?RefusalPresenter $presenter = null;
    private ?OutputInterface $output = null;
    private ?string $format = null;
    private ?ConfigurationOrigin $limitSource = null;
    /** @var array<int, Closure(): void> */
    private array $releases = [];
    private int $nextRelease = 0;

    private function __construct(private readonly int $originalErrorReporting)
    {
        $this->reserve = str_repeat(' ', 256 * 1024);
    }

    public static function register(): self
    {
        if (self::$registered !== null) {
            return self::$registered;
        }

        $hint = new self(error_reporting());
        self::$registered = $hint;
        ini_set('display_errors', 'stderr');
        // PHP renders a fatal before shutdown callbacks. The callback replaces
        // only a memory fatal and restores the other E_ERROR diagnostics itself.
        error_reporting(error_reporting() & ~\E_ERROR);
        register_shutdown_function($hint->onShutdown(...));

        return $hint;
    }

    public static function forWorker(string $nonce): void
    {
        $hint = self::$registered ??= new self(error_reporting());
        if ($hint->workerNonce !== null) {
            return;
        }
        $hint->workerNonce = $nonce;
        ini_set('display_errors', '0');
        // PHP writes a fatal to the worker's private log before shutdown. The
        // parent can read it even if the event loop keeps the child alive.
        ini_set('log_errors', '1');
        set_error_handler(static function (int $level, string $message, string $file, int $line) use ($hint): bool {
            if ((error_reporting() & $level) === 0) {
                return true;
            }
            $severity = match ($level) {
                \E_NOTICE, \E_USER_NOTICE => 'Notice',
                \E_DEPRECATED, \E_USER_DEPRECATED => 'Deprecated',
                default => 'Warning',
            };
            $hint->workerNote(['kind' => 'diagnostic', 'message' => $message, 'file' => $file, 'line' => $line, 'severity' => $severity]);
            return true;
        }, \E_WARNING | \E_NOTICE | \E_USER_WARNING | \E_USER_NOTICE | \E_DEPRECATED | \E_USER_DEPRECATED);
        register_shutdown_function($hint->workerShutdown(...));
    }

    public static function workingOnFile(?string $file): void
    {
        if (self::$registered?->workerNonce !== null) {
            $error = error_get_last();
            if ($file === null && $error !== null && \in_array($error['type'], [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR], true)) {
                return;
            }
            self::$registered->workerFile = $file;
        }
    }

    /** @param array<string, int|string> $note */
    private function workerNote(array $note): void
    {
        fwrite(\STDERR, 'QMX-WORKER-NOTE ' . $this->workerNonce . ' ' . base64_encode(json_encode($note, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE)) . "\n");
    }

    private function workerShutdown(): void
    {
        $error = error_get_last();
        $this->reserve = null;
        if ($error === null || !\in_array($error['type'], [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR], true) || $this->workerFile === null) {
            return;
        }
        $this->workerNote([
            'kind' => 'death',
            'file' => $this->workerFile,
            'message' => $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'],
            'limit' => (string) \ini_get('memory_limit'),
        ]);
    }

    public function answerThrough(RefusalPresenter $presenter, OutputInterface $output, ?string $format): void
    {
        $this->presenter = $presenter;
        $this->output = $output;
        $this->format = $format;
    }

    public function limitSetBy(?ConfigurationOrigin $source): void
    {
        $this->limitSource = $source;
    }

    /**
     * @param Closure(): void $release
     *
     * @return Closure(): void Removes this callback after its target is published.
     */
    public function onExhaustion(Closure $release): Closure
    {
        $id = $this->nextRelease++;
        $this->releases[$id] = $release;

        return function () use ($id): void {
            unset($this->releases[$id]);
        };
    }

    public function unbind(): void
    {
        $this->presenter = null;
        $this->output = null;
        $this->format = null;
        $this->limitSource = null;
        $this->releases = [];
    }

    private function onShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || $error['type'] !== \E_ERROR) {
            return;
        }
        if (!str_starts_with($error['message'], 'Allowed memory size of ')) {
            if (($this->originalErrorReporting & \E_ERROR) !== 0) {
                fwrite(\STDERR, \sprintf("Fatal error: %s in %s on line %d\n", $error['message'], $error['file'], $error['line']));
            }

            return;
        }

        if ($this->reserve === null) {
            return;
        }
        $this->reserve = null;
        $presenter = $this->presenter;
        $output = $this->output;
        $format = $this->format;
        $source = $this->limitSource?->describe() ?? 'php.ini';
        $releases = $this->releases;
        $this->unbind();
        foreach ($releases as $release) {
            try {
                $release();
            } catch (Throwable) {
                // A failed target release must not suppress the process outcome.
            }
        }

        $message = \sprintf(
            'Qualimetrix ran out of memory at %s:%d (memory_limit=%s, source=%s); raise --memory-limit or memory_limit in qmx.yaml.',
            $error['file'],
            $error['line'],
            \ini_get('memory_limit'),
            $source,
        );
        if ($presenter !== null && $output !== null) {
            $presenter->resourceExhausted($output, $format, $message);
        } else {
            fwrite(\STDERR, $message . "\n");
        }
        exit(ConsoleExitCode::AnalysisIncomplete->value);
    }
}
