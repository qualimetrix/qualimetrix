<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\CancelledException;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\StatusError;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerException;
use Amp\TimeoutCancellation;
use Closure;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Throwable;

use function Amp\async;

final class WorkerPool
{
    private bool $closed = false;

    private function __construct(private readonly ContextWorkerPool $pool, private readonly int $workerCount, private readonly WorkerContextFactory $contexts, private readonly WorkerNotes $notes) {}

    public static function unavailableCapability(): ?string
    {
        $disabled = array_map(trim(...), explode(',', (string) \ini_get('disable_functions')));
        if (!\function_exists('proc_open') || \in_array('proc_open', $disabled, true)) {
            return 'proc_open';
        }
        return \PHP_OS_FAMILY !== 'Windows' && !\extension_loaded('posix') ? 'ext-posix' : null;
    }

    public static function open(int $workerCount, LoggerInterface $logger, WorkerContextFactory $contexts, WorkerNotes $notes): self
    {
        set_error_handler(static function (int $level, string $message, string $file) use ($logger): bool {
            if (!str_contains(str_replace('\\', '/', $file), '/amphp/parallel/')) {
                return false;
            }
            $logger->debug('Worker pool notice: ' . $message);
            return true;
        }, \E_USER_WARNING | \E_USER_NOTICE | \E_DEPRECATED | \E_USER_DEPRECATED);
        return new self(new ContextWorkerPool($workerCount, new ContextWorkerFactory(contextFactory: $contexts)), $workerCount, $contexts, $notes);
    }

    public function probe(WorkerProbeTask $task, WorkerRequest $request): void
    {
        $future = async(fn(): mixed => $this->pool->submit($task)->getFuture()->await());
        try {
            if ($future->await(new TimeoutCancellation(WorkerRequest::START_DEADLINE_SECONDS)) !== true) {
                throw WorkerStartFailure::failedToStart('the bootstrap probe refused the worker', '', $request);
            }
        } catch (CancelledException $failure) {
            $log = $this->contexts->startupExcerpt();
            $this->pool->kill();
            try {
                $future->await(new TimeoutCancellation(WorkerRequest::START_DEADLINE_SECONDS));
            } catch (Throwable) {
                $future->ignore();
            }
            $this->close();
            throw WorkerStartFailure::noWorkerWithin(WorkerRequest::START_DEADLINE_SECONDS, $log, $request);
        } catch (WorkerStartFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            $reason = $failure instanceof TaskFailureThrowable ? $failure->getOriginalMessage() : $failure->getMessage();
            throw WorkerStartFailure::failedToStart($reason, $this->contexts->startupExcerpt(), $request);
        }
    }

    /** @return Closure(): FileProcessingResult */
    public function submit(FileProcessingTask $task): Closure
    {
        $refusal = new WorkerException('No worker accepted the task.');
        for ($attempt = 0; $attempt <= $this->workerCount; ++$attempt) {
            try {
                $execution = $this->pool->submit($task);
                return static fn(): FileProcessingResult => $execution->getFuture()->await();
            } catch (WorkerException $refused) {
                $refusal = $refused;
            }
        }
        return static fn(): never => throw $refusal;
    }

    public function startupFailure(Throwable $failure): ?WorkerStartFailure
    {
        if ($failure instanceof WorkerStartFailure) {
            return $failure;
        }
        return !$this->pool->isRunning() && ($failure instanceof StatusError || $failure instanceof ContextException || $failure instanceof WorkerException) ? $this->contexts->failure() : null;
    }

    public function failureMessage(Throwable $failure, string $file): string
    {
        if ($failure instanceof TaskFailureThrowable) {
            return $failure->getOriginalMessage();
        }
        if (!$failure instanceof ContextException && !$failure instanceof WorkerException) {
            return $failure->getMessage();
        }
        if ($failure instanceof WorkerPhpFailure) {
            return \sprintf('%s (worker file %s, memory_limit="%s").', $failure->diagnostic, $file, (string) \ini_get('memory_limit'));
        }
        return $this->notes->deathFor($file) ?? \sprintf('The worker process handling this file died (%s). Workers run with memory_limit "%s"; raise --memory-limit or memory_limit in qmx.yaml.', $failure->getMessage(), (string) \ini_get('memory_limit'));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            if ($this->pool->isRunning()) {
                $this->pool->shutdown();
            } else {
                $this->pool->kill();
            }
        } finally {
            try {
                $this->contexts->close();
            } finally {
                restore_error_handler();
            }
        }
    }

    public function abort(): void
    {
        if ($this->closed) {
            return;
        }
        $this->pool->kill();
        $this->close();
    }
}
