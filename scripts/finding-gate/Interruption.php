<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Turns a signal into a stop the run takes at a point of its own choosing.
 *
 * PHP's default disposition terminates the process without running `finally` or
 * a shutdown function, so a run killed this way keeps everything it holds:
 * measured 2026-09-14, a SIGINT during the reference phase left both scratch
 * directories and a reference checkout still registered in the developer's
 * repository.
 *
 * The handler only records the signal. The decision is taken synchronously, by
 * {@see raiseIfRequested()} at the points listed there — the idiom the controls
 * harness already argues for in its own `Shell`. Throwing
 * from the handler itself was tried on paper and rejected: two of the gate's
 * own `catch (GateError)` sites swallow and continue, and both wrap CPU-bound
 * string work, which is where an asynchronous signal is most likely to land. An
 * interrupt would have disappeared into one of them and the run would have gone
 * on to publish a finding about a comparison nobody completed.
 *
 * Only the first signal counts, and the handlers are disarmed once it arrives:
 * a second Ctrl-C must not cut `git worktree remove` in half and leave the
 * locked registration that neither `prune` nor a single `--force` can clear.
 * SIGQUIT is deliberately left alone as the escape hatch for a cleanup that
 * really is stuck.
 */
final class Interruption
{
    /** SIGHUP included: closing the terminal on a `composer gate` is the same exit, and it is the same default. */
    private const SIGNALS = [\SIGINT, \SIGTERM, \SIGHUP];

    private static bool $armed = false;

    private static ?int $received = null;

    private static bool $raised = false;

    private static bool $suppressed = false;

    /**
     * Refuses without pcntl rather than carrying on quietly.
     *
     * {@see ProcessHandle::start()} already makes posix a hard requirement for
     * the same kind of reason. A silent no-op here would mean the leak this
     * class exists to close stays open on that machine while every test written
     * against it passes, having checked nothing.
     */
    public static function arm(): void
    {
        if (self::$armed) {
            return;
        }

        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_signal')) {
            throw new GateError(
                'Releasing a run\'s scratch state on an interrupt requires the PHP pcntl extension.',
            );
        }

        pcntl_async_signals(true);

        foreach (self::SIGNALS as $signal) {
            pcntl_signal($signal, self::record(...));
        }

        self::$armed = true;
    }

    /**
     * Stops the run if a signal arrived, and does so exactly once.
     *
     * Called from {@see Process::poll()}, {@see CaseScheduler::poll()} and the
     * head of the gate's surface comparison — a child is being waited on, or a
     * surface is about to be compared. Once is the whole point: the cleanup
     * this throw sets off runs `git` through {@see Process::run()}, and a second
     * throw from that poll would abandon the cleanup halfway.
     */
    public static function raiseIfRequested(): void
    {
        if (self::$received === null || self::$raised || self::$suppressed) {
            return;
        }

        self::$raised = true;

        throw new Interrupted(\sprintf('Interrupted by signal %d.', self::$received));
    }

    /**
     * From here on the run is handing things back, and an interrupt must not be
     * raised again.
     *
     * Releasing the reference checkout runs `git` through {@see Process::run()},
     * whose poll is itself a decision point. Without this, a signal that arrived
     * where no decision point followed — during the comparison phase, say — would
     * be raised for the first time *inside* the cleanup and abandon it halfway,
     * which is the locked registration all over again.
     */
    public static function stopRaising(): void
    {
        self::$suppressed = true;
    }

    /** The signal that stopped the run, or null if none did. */
    public static function signal(): ?int
    {
        return self::$received;
    }

    /**
     * `128 + n` for whatever signal was recorded, null if none was.
     *
     * Recorded, not acted on: this is what a step asks when it is about to do
     * something a stopped run must not do — writing a tracked declaration, say.
     * A signal that arrives in the tail of a run reaches no decision point, and
     * a Ctrl-C must still not be the last thing a developer does before the
     * declaration changes under them.
     */
    public static function exitCode(): ?int
    {
        return self::$received === null ? null : 128 + self::$received;
    }

    /**
     * Whether an interrupt actually stopped the run, rather than merely being
     * recorded.
     *
     * What the exit code is read from, and read from here rather than from
     * catching {@see Interrupted}: a stopping run also terminates its workers,
     * and {@see CaseScheduler::run()} rethrows a termination failure out of its
     * own `finally`, which would replace the interrupt. And conditioned on the
     * raise because every finished run suppresses raising while it hands its
     * scratch back — without that, a signal recorded afterwards would make the
     * next ordinary `GateError` report itself as 130, and the documented "the
     * gate could not run" 3 would be unreachable for the rest of the process.
     */
    public static function stoppedRun(): bool
    {
        return self::$raised;
    }

    private static function record(int $signal): void
    {
        self::$received ??= $signal;

        foreach (self::SIGNALS as $handled) {
            pcntl_signal($handled, \SIG_IGN);
        }
    }
}
