<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerException;
use Closure;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Throwable;

/**
 * The amphp worker pool one parallel collection runs on, made to answer a
 * dead worker with a failed file instead of ending the run.
 *
 * A worker that dies with a file in hand — a PHP fatal error such as an
 * exhausted `memory_limit` — reaches the coordinator only as a context or
 * worker that stopped responding. Three consequences are handled here: the
 * task a dead worker refused before starting it is sent again, the file it
 * died on is reported with the workers' limit named, and the pool's own
 * `trigger_error()` about the removed worker goes to the log rather than
 * onto stdout between a machine report's bytes.
 */
final class WorkerPool
{
    private function __construct(
        private readonly ContextWorkerPool $pool,
        private readonly int $workerCount,
    ) {}

    /** amphp needs either ext-parallel or pcntl to run a worker. */
    public static function isAvailable(): bool
    {
        return class_exists(ContextWorkerPool::class)
            && (\extension_loaded('parallel') || \function_exists('pcntl_fork'));
    }

    /**
     * Opens a pool. Until {@see self::close()}, the pool's notices go to
     * `$logger`; any other error keeps PHP's own handling.
     */
    public static function open(int $workerCount, LoggerInterface $logger): self
    {
        set_error_handler(
            static function (int $level, string $message, string $file) use ($logger): bool {
                if (!str_contains(str_replace('\\', '/', $file), '/amphp/parallel/')) {
                    return false;
                }

                $logger->warning('AmphpParallelStrategy: ' . $message);

                return true;
            },
            \E_USER_WARNING | \E_USER_NOTICE | \E_DEPRECATED | \E_USER_DEPRECATED,
        );

        return new self(new ContextWorkerPool($workerCount), $workerCount);
    }

    /**
     * Submits one task and answers with what awaits its result, which throws
     * whatever failed the file.
     *
     * A worker that died on an earlier file is returned to the pool as idle
     * and found dead only when the next task is sent to it. That task never
     * started, so it is sent again and the pool replaces the worker. Thrown
     * from here, the refusal ended the whole run as an internal error over a
     * file that was never analysed.
     *
     * @return Closure(): FileProcessingResult
     */
    public function submit(FileProcessingTask $task): Closure
    {
        $refusal = new WorkerException('No worker accepted the task.');
        for ($attempt = 0; $attempt <= $this->workerCount; $attempt++) {
            try {
                $execution = $this->pool->submit($task);

                return static fn(): FileProcessingResult => $execution->getFuture()->await();
            } catch (WorkerException $refused) {
                $refusal = $refused;
            }
        }

        return static fn(): never => throw $refusal;
    }

    /**
     * What a failed file is reported with.
     *
     * An exception thrown inside the task arrives as a task failure and
     * already says what went wrong. A dead worker's fatal error goes to
     * stderr straight from the worker process, past this code, so the cause
     * cannot be read here; the workers' limit is named instead, so a reader
     * can match it to that stderr line.
     */
    public static function failureMessage(Throwable $failure): string
    {
        if (!$failure instanceof ContextException && !$failure instanceof WorkerException) {
            return $failure->getMessage();
        }

        return \sprintf(
            'The worker process handling this file died (%s). Workers run with memory_limit "%s": if stderr'
            . ' shows "Allowed memory size of ... bytes exhausted", raise --memory-limit or memory_limit in qmx.yaml.',
            $failure->getMessage(),
            (string) \ini_get('memory_limit'),
        );
    }

    public function close(): void
    {
        try {
            $this->pool->shutdown();
        } finally {
            restore_error_handler();
        }
    }
}
