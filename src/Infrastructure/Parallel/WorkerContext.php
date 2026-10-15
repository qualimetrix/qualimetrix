<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Cancellation;
use Amp\Future;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\TimeoutCancellation;
use Closure;
use Throwable;

/** @implements Context<mixed, mixed, mixed> */
final readonly class WorkerContext implements Context
{
    /**
     * @param Context<mixed, mixed, mixed> $context
     * @param list<Future<void>> $readers
     */
    public function __construct(private Context $context, private array $readers, private string $phpLog) {}

    public function receive(?Cancellation $cancellation = null): mixed
    {
        try {
            return $this->context->receive($cancellation);
        } catch (ContextException $failure) {
            throw $this->diagnosedFailure($failure);
        }
    }

    public function send(mixed $data): void
    {
        try {
            $this->context->send($data);
        } catch (ContextException $failure) {
            throw $this->diagnosedFailure($failure);
        }
    }

    private function diagnosedFailure(ContextException $failure): ContextException
    {
        $diagnostic = $this->fatalDiagnostic();
        if ($diagnostic === null) {
            $this->drainNotes();
            $diagnostic = $this->fatalDiagnostic();
        }

        return $diagnostic === null ? $failure : new WorkerPhpFailure($diagnostic, $failure);
    }

    private function fatalDiagnostic(): ?string
    {
        if (!is_file($this->phpLog)) {
            return null;
        }
        $log = file_get_contents($this->phpLog);
        if ($log === false) {
            return null;
        }
        foreach (array_reverse(explode("\n", trim($log))) as $line) {
            if (preg_match('/PHP (?:Fatal|Parse|Core|Compile) error:\s*(.+)/', $line, $matches) === 1) {
                return $matches[1];
            }
        }
        return null;
    }

    private function drainNotes(): void
    {
        // amphp kills the context on IPC failure. Drain the dying process's
        // shutdown note before exposing that failure to the worker pool.
        $deadline = new TimeoutCancellation(WorkerContextFactory::START_DEADLINE_SECONDS);
        foreach ($this->readers as $reader) {
            try {
                $reader->await($deadline);
            } catch (Throwable) {
                break;
            }
        }
    }

    public function join(?Cancellation $cancellation = null): mixed
    {
        return $this->context->join($cancellation);
    }

    public function close(): void
    {
        $this->context->close();
    }

    public function isClosed(): bool
    {
        return $this->context->isClosed();
    }

    public function onClose(Closure $onClose): void
    {
        $this->context->onClose($onClose);
    }
}
