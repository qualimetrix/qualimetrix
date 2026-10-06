<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use Amp\CancelledException;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Throwable;

/** One console run's interruption latch and temporary signal ownership. */
final class StagedSignalGuard
{
    /** @var array<int, mixed> */
    private array $previousHandlers = [];

    private bool $previousAsync = false;
    private bool $closing = false;
    private ?int $interrupted = null;

    private function __construct(private readonly int $ownerPid) {}

    public static function start(string $spelling): self
    {
        self::assertAvailable();
        $ownerPid = self::ownerPid();
        self::assertNoForeignWatcher($spelling);
        $guard = new self($ownerPid);
        $guard->rememberDefaultHandlers($spelling);
        $guard->register($spelling);

        return $guard;
    }

    private static function assertAvailable(): void
    {
        $available = get_defined_functions()['internal'];
        foreach (['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals'] as $function) {
            if (!\in_array($function, $available, true)) {
                throw EnvironmentRefusal::aboutCapability($function, 'Staged regular output', 'Use a PHP runtime with PCNTL or select a stream target.');
            }
        }
    }

    private static function ownerPid(): int
    {
        $ownerPid = getmypid();
        if ($ownerPid === false) {
            throw EnvironmentRefusal::aboutCapability('getmypid', 'Staged regular output', 'The owner process cannot be identified.');
        }

        return $ownerPid;
    }

    private static function assertNoForeignWatcher(string $spelling): void
    {
        foreach (EventLoop::getIdentifiers() as $callbackId) {
            if (EventLoop::getType($callbackId) === CallbackType::Signal) {
                throw EnvironmentRefusal::aboutFile($spelling, 'stage', 'an existing event-loop signal watcher may replace the staged output handler');
            }
        }
    }

    private function rememberDefaultHandlers(string $spelling): void
    {
        foreach ([\SIGINT, \SIGTERM] as $signal) {
            $previous = pcntl_signal_get_handler($signal);
            if ($previous !== \SIG_DFL) {
                throw EnvironmentRefusal::aboutFile($spelling, 'stage', 'an existing signal handler owns SIGINT or SIGTERM');
            }
            $this->previousHandlers[$signal] = $previous;
        }
    }

    private function register(string $spelling): void
    {
        $this->previousAsync = pcntl_async_signals();
        try {
            pcntl_async_signals(true);
            foreach ([\SIGINT, \SIGTERM] as $signal) {
                pcntl_signal($signal, $this->receive(...));
            }
        } catch (Throwable $failure) {
            $this->close();
            throw EnvironmentRefusal::aboutFile($spelling, 'stage', 'signal watching is unavailable: ' . $failure->getMessage());
        }
    }

    public function interruptedSignal(): ?int
    {
        return $this->interrupted;
    }

    public function assertNotInterrupted(): void
    {
        if ($this->interrupted !== null) {
            throw new CancelledException();
        }
    }

    public function receive(int $signal): void
    {
        $currentPid = getmypid();
        if ($currentPid === false) {
            exit(128 + $signal);
        }
        if ($this->ownerPid !== $currentPid) {
            pcntl_signal($signal, \SIG_DFL);
            if (\function_exists('posix_kill')) {
                posix_kill($currentPid, $signal);
            }
            exit(128 + $signal);
        }
        $this->interrupted ??= $signal;
        if (!$this->closing) {
            $this->assertNotInterrupted();
        }
    }

    public function close(): void
    {
        $this->beginCleanup();
        foreach ($this->previousHandlers as $signal => $handler) {
            pcntl_signal($signal, $this->ownerPid === getmypid() ? $handler : \SIG_DFL);
        }
        $this->previousHandlers = [];
        if ($this->ownerPid === getmypid()) {
            pcntl_async_signals($this->previousAsync);
        }
    }

    public function beginCleanup(): void
    {
        $this->closing = true;
    }
}
