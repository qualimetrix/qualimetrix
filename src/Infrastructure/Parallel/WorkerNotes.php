<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\ByteStream\ReadableResourceStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use Closure;
use JsonException;
use Qualimetrix\Infrastructure\Parallel\Contract\WorkerDiagnosticsInterface;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\splitLines;

final class WorkerNotes
{
    /** @var array<string, string> */
    private array $deaths = [];
    /** @var array<string, DeferredFuture<string>> */
    private array $awaitingDeaths = [];
    /** @var list<Future<void>> */
    private array $readers = [];
    private readonly DeferredCancellation $cancel;

    public function __construct(private readonly WorkerDiagnosticsInterface $diagnostics)
    {
        $this->cancel = new DeferredCancellation();
    }

    /** @return Future<void> */
    public function captureStdout(ReadableResourceStream $stream): Future
    {
        return $this->capture($stream, $this->stdoutLine(...));
    }

    /** @return Future<void> */
    public function captureStderr(ReadableResourceStream $stream, string $nonce): Future
    {
        return $this->capture($stream, fn(string $line) => $this->stderrLine($nonce, $line));
    }

    /** @param Closure(string): void $consume
     * @return Future<void>
     */
    private function capture(ReadableResourceStream $stream, Closure $consume): Future
    {
        return $this->readers[] = async(function () use ($stream, $consume): void {
            try {
                foreach (splitLines($stream, $this->cancel->getCancellation()) as $line) {
                    $consume($line);
                }
            } catch (CancelledException) {
                return;
            }
        });
    }

    public function stdoutLine(string $line): void
    {
        $this->diagnostic('worker: ' . $line);
    }

    public function stderrLine(string $nonce, string $line): void
    {
        $note = $this->decodeNote($nonce, $line);
        if ($note === null) {
            $this->stdoutLine($line);
            return;
        }
        if ($this->recordDeathIfPresent($note)) {
            return;
        }
        if ($this->reportDiagnosticIfPresent($note)) {
            return;
        }
        $this->stdoutLine($line);
    }

    /** @param array<string, mixed> $note */
    private function recordDeathIfPresent(array $note): bool
    {
        if (($note['kind'] ?? null) !== 'death' || !\is_string($note['limit'] ?? null)) {
            return false;
        }
        $this->recordDeath($note['file'], $note['message'], $note['limit']);
        return true;
    }

    /** @param array<string, mixed> $note */
    private function reportDiagnosticIfPresent(array $note): bool
    {
        if (($note['kind'] ?? null) !== 'diagnostic' || !\is_int($note['line'] ?? null) || !\is_string($note['severity'] ?? null)) {
            return false;
        }
        $this->diagnostic(\sprintf('PHP %s:  %s in %s on line %d', $note['severity'], $note['message'], $note['file'], $note['line']));
        return true;
    }

    /** @return array<string, mixed>|null */
    private function decodeNote(string $nonce, string $line): ?array
    {
        $prefix = 'QMX-WORKER-NOTE ' . $nonce . ' ';
        if (!str_starts_with($line, $prefix)) {
            return null;
        }
        $payload = base64_decode(substr($line, \strlen($prefix)), true);
        try {
            $note = $payload === false ? null : json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!\is_array($note) || !\is_string($note['file'] ?? null) || !\is_string($note['message'] ?? null)) {
            return null;
        }

        return $note;
    }

    private function recordDeath(string $file, string $detail, string $limit): void
    {
        $message = $detail . ' (worker file ' . $file . ', memory_limit="' . $limit . '").';
        $this->deaths[$file] = $message;
        if (isset($this->awaitingDeaths[$file])) {
            $this->awaitingDeaths[$file]->complete($message);
            unset($this->awaitingDeaths[$file]);
        }
    }

    public function diagnostic(string $message): void
    {
        $this->diagnostics->diagnostic($message);
    }

    public function deathFor(string $file): ?string
    {
        if (isset($this->deaths[$file])) {
            return $this->deaths[$file];
        }
        $future = $this->awaitingDeaths[$file] ??= new DeferredFuture();
        try {
            return $future->getFuture()->await(new TimeoutCancellation(WorkerRequest::START_DEADLINE_SECONDS));
        } catch (CancelledException) {
            return null;
        } finally {
            unset($this->awaitingDeaths[$file]);
        }
    }

    public function close(): void
    {
        try {
            foreach ($this->readers as $reader) {
                try {
                    $reader->await(new TimeoutCancellation(WorkerRequest::START_DEADLINE_SECONDS));
                } catch (Throwable) {
                    continue;
                }
            }
        } finally {
            $this->cancel->cancel();
            $this->readers = [];
            $this->deaths = [];
            $this->awaitingDeaths = [];
        }
    }
}
