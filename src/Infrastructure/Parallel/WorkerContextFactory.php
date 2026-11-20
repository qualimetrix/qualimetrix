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
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\FileTarget\NativeCall;

/**
 * @qmx-threshold coupling.instability 0.894738 -- The process-context factory binds IPC startup, cancellation, diagnostics and private startup logs for the worker pool; these transport dependencies stay together for its few consumers.
 */
final class WorkerContextFactory implements ContextFactory
{
    private readonly LocalIpcHub $hub;
    private readonly string $directory;
    /** @var list<string> */
    private array $startupFiles = [];
    private ?WorkerStartFailure $failure = null;
    private ?Closure $removeExhaustion = null;
    private bool $closed = false;

    /**
     * @param non-empty-list<string> $binary
     * @param (Closure(Closure(): void): (Closure(): void))|null $registerExhaustion
     */
    public function __construct(private readonly WorkerRequest $request, private readonly WorkerNotes $notes, private readonly array $binary = [\PHP_BINARY], private readonly LoggerInterface $logger = new NullLogger(), ?Closure $registerExhaustion = null)
    {
        $refusal = self::admitInheritedDescriptors();
        if ($refusal !== null) {
            throw WorkerStartFailure::failedToStart($refusal, '', $request);
        }
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
        $this->removeExhaustion = $registerExhaustion?->__invoke(function (): void {
            $this->hub->close();
            $this->cleanupDirectory();
        });
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

    /**
     * amphp/process maps every descriptor this process holds onto `/dev/null`
     * at the same number in the worker. A descriptor inherited from the
     * launcher (an IDE, a Git client, a terminal) can be numbered at or above
     * the soft open-files limit, and the worker cannot occupy that number, so
     * `proc_open()` fails with "Bad file descriptor". The soft limit is raised
     * within the hard limit and stays raised, so replacement workers fit too.
     *
     * @return ?string Why worker processes cannot start, or null when they can.
     */
    private static function admitInheritedDescriptors(): ?string
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('posix_setrlimit')) {
            return null;
        }
        $required = self::highestOpenDescriptor() + 1;
        $limits = posix_getrlimit(\POSIX_RLIMIT_NOFILE);
        if ($limits === false || !isset($limits[0], $limits[1])) {
            return null;
        }
        // posix_getrlimit() reports an infinite limit as the string "unlimited".
        [$soft, $hard] = array_map(static fn(int|string $limit): int => \is_int($limit) ? $limit : \PHP_INT_MAX, [$limits[0], $limits[1]]);
        if ($soft >= $required) {
            return null;
        }
        $refusal = \sprintf('an inherited file descriptor is numbered %d, beyond the open-files limit %d', $required - 1, $soft);
        if ($hard < $required) {
            return $refusal . \sprintf(' that the hard limit %d does not let qmx raise; raise it with `ulimit -n` or start qmx without that descriptor', $hard);
        }
        [$raised, $warning] = NativeCall::attempt(static fn(): bool => posix_setrlimit(\POSIX_RLIMIT_NOFILE, $required, $hard === \PHP_INT_MAX ? \POSIX_RLIMIT_INFINITY : $hard));

        return $raised === true ? null : $refusal . ', and raising that limit failed: ' . ($warning ?? posix_strerror(posix_get_last_error()));
    }

    private static function highestOpenDescriptor(): int
    {
        // The same listing amphp/process reads to choose the descriptors it maps.
        $directory = file_exists('/dev/fd') ? '/dev/fd' : '/proc/self/fd';
        [$names] = NativeCall::attempt(static fn(): array|false => scandir($directory, \SCANDIR_SORT_NONE));
        $highest = 2;
        foreach ($names === false ? [] : $names as $name) {
            if (ctype_digit($name)) {
                $highest = max($highest, (int) $name);
            }
        }

        return $highest;
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
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->removeExhaustion?->__invoke();
        $this->removeExhaustion = null;
        try {
            $this->hub->close();
            $this->notes->close();
        } finally {
            $this->cleanupDirectory();
        }
    }

    private function cleanupDirectory(): void
    {
        $names = scandir($this->directory);
        foreach ($names === false ? [] : $names as $name) {
            if (!str_starts_with($name, 'start-') || !str_ends_with($name, '.log')) {
                continue;
            }
            $file = $this->directory . '/' . $name;
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }
}
