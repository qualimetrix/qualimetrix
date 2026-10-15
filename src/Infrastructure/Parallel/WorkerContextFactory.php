<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\FileTarget\NativeCall;

/**
 * @qmx-threshold coupling.instability warning=0.894737 -- The process-context factory binds IPC startup, cancellation, diagnostics and private startup logs for the worker pool; these transport dependencies stay together for its few consumers.
 */
final class WorkerContextFactory implements ContextFactory
{
    private readonly LocalIpcHub $hub;
    private readonly string $directory;
    /** @var list<string> */
    private array $startupFiles = [];
    private ?WorkerStartFailure $failure = null;

    /** @param non-empty-list<string> $binary */
    public function __construct(private readonly WorkerRequest $request, private readonly WorkerNotes $notes, private readonly array $binary = [\PHP_BINARY], private readonly LoggerInterface $logger = new NullLogger())
    {
        try {
            $this->hub = new LocalIpcHub();
        } catch (SocketException $failure) {
            throw WorkerStartFailure::failedToStart('cannot open local worker IPC: ' . $failure->getMessage(), '', $request);
        }
        $this->directory = sys_get_temp_dir() . '/qmx-worker-start-' . bin2hex(random_bytes(8));
        [$created, $warning] = NativeCall::attempt(fn(): bool => mkdir($this->directory, 0700));
        if (!$created) {
            $this->hub->close();
            throw WorkerStartFailure::failedToStart('cannot create worker startup directory ' . $this->directory . ': ' . ($warning ?? 'unknown error'), '', $request);
        }
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @return Context<mixed, mixed, mixed>
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): Context
    {
        $file = $this->directory . '/start-' . bin2hex(random_bytes(8)) . '.log';
        $this->startupFiles[] = $file;
        $nonce = bin2hex(random_bytes(8));
        $environment = getenv();
        $environment['QMX_WORKER_NOTE_NONCE'] = $nonce;
        $binary = [...$this->binary, '-d', 'error_log=' . $file];
        foreach (['short_open_tag', 'zend.multibyte', 'zend.script_encoding'] as $setting) {
            $binary[] = '-d';
            $binary[] = $setting . '=' . (string) \ini_get($setting);
        }
        $deadline = new TimeoutCancellation(WorkerRequest::START_DEADLINE_SECONDS);
        $combined = $cancellation === null ? $deadline : new CompositeCancellation($cancellation, $deadline);
        try {
            $context = ProcessContext::start($this->hub, $script, environment: $environment, cancellation: $combined, binary: $binary, childConnectTimeout: WorkerRequest::START_DEADLINE_SECONDS);
            $this->logger->debug('Worker process connected', ['pid' => $context->getPid()]);
            $readers = [$this->notes->captureStdout($context->getStdout()), $this->notes->captureStderr($context->getStderr(), $nonce)];
            return new WorkerContext($context, $readers, $file);
        } catch (CancelledException $failure) {
            if ($cancellation?->isRequested() === true) {
                throw $failure;
            }
            throw $this->failure = WorkerStartFailure::noWorkerWithin(WorkerRequest::START_DEADLINE_SECONDS, self::excerpt($file), $this->request);
        } catch (ContextException $failure) {
            throw $this->failure = WorkerStartFailure::failedToStart($failure->getMessage(), self::excerpt($file), $this->request);
        } finally {
            if (!isset($context) && is_file($file)) {
                unlink($file);
            }
        }
    }

    public function failure(): ?WorkerStartFailure
    {
        return $this->failure;
    }

    public function startupExcerpt(): string
    {
        return implode("\n", array_filter(array_map(self::excerpt(...), $this->startupFiles), static fn(string $log): bool => $log !== ''));
    }

    private static function excerpt(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }
        $text = file_get_contents($file);
        return $text === false ? '' : implode("\n", \array_slice(explode("\n", trim($text)), 0, 20));
    }

    public function close(): void
    {
        try {
            $this->hub->close();
            $this->notes->close();
        } finally {
            foreach ($this->startupFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->directory);
        }
    }
}
