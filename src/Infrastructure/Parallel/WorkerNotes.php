<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\ByteStream\ReadableResourceStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
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
    public function capture(ReadableResourceStream $stream, string $nonce, bool $notes): Future
    {
        return $this->readers[] = async(function () use ($stream, $nonce, $notes): void {
            try {
                foreach (splitLines($stream, $this->cancel->getCancellation()) as $line) {
                    $this->line($nonce, $line, $notes);
                }
            } catch (CancelledException) {
                // Closing the pool cancels its own readers after bounded draining.
            }
        });
    }

    public function line(string $nonce, string $line, bool $notes): void
    {
        $prefix = 'QMX-WORKER-NOTE ' . $nonce . ' ';
        if (!$notes || !str_starts_with($line, $prefix)) {
            $this->diagnostic('worker: ' . $line);
            return;
        }
        $payload = base64_decode(substr($line, \strlen($prefix)), true);
        try {
            $note = $payload === false ? null : json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $note = null;
        }
        if (!\is_array($note) || !\is_string($note['file'] ?? null) || !\is_string($note['message'] ?? null)) {
            $this->diagnostic('worker: ' . $line);
            return;
        }
        if (($note['kind'] ?? null) === 'death' && \is_string($note['limit'] ?? null)) {
            $message = $note['message'] . ' (worker file ' . $note['file'] . ', memory_limit="' . $note['limit'] . '").';
            $this->deaths[$note['file']] = $message;
            if (isset($this->awaitingDeaths[$note['file']])) {
                $this->awaitingDeaths[$note['file']]->complete($message);
                unset($this->awaitingDeaths[$note['file']]);
            }
            return;
        }
        if (($note['kind'] ?? null) === 'diagnostic' && \is_int($note['line'] ?? null) && \is_string($note['severity'] ?? null)) {
            $this->diagnostic(\sprintf('PHP %s:  %s in %s on line %d', $note['severity'], $note['message'], $note['file'], $note['line']));
            return;
        }
        $this->diagnostic('worker: ' . $line);
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
            return $future->getFuture()->await(new TimeoutCancellation(WorkerContextFactory::START_DEADLINE_SECONDS));
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
                    $reader->await(new TimeoutCancellation(WorkerContextFactory::START_DEADLINE_SECONDS));
                } catch (Throwable) {
                    // A closed transport cannot prevent cleanup of the remaining streams.
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
