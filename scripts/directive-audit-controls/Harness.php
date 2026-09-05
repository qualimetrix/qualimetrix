<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

use QmxFindingGateControls\Child;
use QmxFindingGateControls\Scratch;
use QmxFindingGateControls\Shell;
use RuntimeException;
use Throwable;

/**
 * Proves the threshold audit's test suite bites.
 *
 * A detector that answers "inert" to everything passes a naive reading: there
 * are few genuinely dead directives in any tree, so "zero inert" looks the same
 * from a working detector and from a broken one. What separates them is whether
 * the suite that guards the detector goes red when the detector is broken — so
 * each breakage below is planted on its own hardlink clone, the suite is run
 * there, and the harness reports which cases noticed.
 *
 * Four conditions decide the run, and "each mutation reddens only its own case"
 * is not among them. It was, in the first edition of the plan, and measurement
 * refuted it: a breakage in the outcome comparison reddens nearly every case at
 * once, which is its purpose. What is checkable:
 *
 * 1. every probe reddens the cases it declares — a claim with no case behind it
 *    is a claim nobody checks;
 * 2. every case is reddened by at least one probe — a case nothing can break is
 *    not evidence about anything;
 * 3. no probe reddens every case — a breakage that fails the whole suite says
 *    nothing about which claim it broke;
 * 4. a mutation that no longer applies is a refusal, not a skip. `Mutation`
 *    enforces that itself, by demanding exactly one occurrence of what it
 *    rewrites; this harness only has to let the refusal through rather than
 *    reading "reddened nothing" as a result. The P2 prototype read it as a
 *    result three times.
 */
final class Harness
{
    /**
     * Measured over a full stand run on an otherwise idle machine (Docker
     * stopped, nothing else running, 2026-09-04): 1799.8s, 481.7s and 443.2s
     * of wall clock at widths 1, 4 and 8, for 1754.6, 1846.6 and 2054.9 CPU
     * seconds (`measurement-stand-wallclock.tsv`). The probe count that run
     * covered is not repeated here — it drifts every time a probe is added,
     * and {@see Probes::all()} is the one place it cannot go stale.  Wall
     * clock keeps falling through width 8, so the ceiling is not "the point
     * where more width stops helping" — it is the point measured, not a
     * plateau found.
     *
     * The CPU growth is not the bench adding work; it is the same work
     * costing more. `measurement-machine-cpu-curve.tsv` (same machine, same
     * date) runs one fixed PHP workload in N simultaneous copies: each copy's
     * user time costs 1.05x its solo user time at 4 concurrent copies, 1.54x
     * at 8, 1.69x at 12 (wall clock grows faster — 1.73x at 12). The growth
     * starts at the fifth concurrent copy, on a machine that reports fourteen
     * logical cores (`sysctl hw.ncpu`) — a fact about this machine, not a
     * diagnosis of why five is where it starts. The ceiling stands on the
     * measured wall clock, not on this explanation of the CPU cost.
     */
    private const JOB_CEILING = 8;

    /** How long a run may say nothing before it names what it is waiting for. */
    private const LIVENESS_INTERVAL_SECONDS = 30.0;

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $repository = \dirname(__DIR__, 2);
        self::armCleanup();

        try {
            $only = self::only($arguments);
        } catch (RuntimeException $error) {
            fwrite(\STDERR, 'directive-audit-controls: ' . $error->getMessage() . "\n");

            return 3;
        }

        $probes = array_values(array_filter(
            Probes::all(),
            static fn(Probe $probe): bool => $only === [] || \in_array($probe->id, $only, true),
        ));

        if ($probes === []) {
            fwrite(\STDERR, "No probe matches --only.\n");

            return 3;
        }

        try {
            $width = min(self::jobs($arguments), \count($probes));
        } catch (RuntimeException $error) {
            fwrite(\STDERR, 'directive-audit-controls: ' . $error->getMessage() . "\n");

            return 3;
        }

        self::report(\sprintf('%d probe(s), %d at a time', \count($probes), $width));

        return Report::of(self::runPool($probes, $repository, $width), $only !== [])->print();
    }

    /**
     * Every scratch tree removed on every way out, including the ones no
     * `finally` sees.
     *
     * A clone left behind is a hardlink farm pointing at the developer's
     * working tree, and the run that leaves it is exactly the interrupted one
     * nobody is watching. Signals only raise a flag — `Shell` acts on it
     * between reads, after killing the process tree — and the shutdown function
     * is the backstop for a fatal error or an exit from elsewhere.
     */
    private static function armCleanup(): void
    {
        register_shutdown_function(static function (): void {
            Shell::terminateAll();
            Scratch::removeAll();
        });

        Shell::superviseFor(false, static function (string $reason): void {
            fwrite(\STDERR, \sprintf("\nInterrupted: %s. Removed the scratch trees.\n", $reason));
        });

        if (!\function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([\SIGINT, \SIGTERM, \SIGHUP] as $signal) {
            pcntl_signal($signal, static function (int $received): void {
                Shell::requestStop('signal ' . $received);
            });
        }
    }

    /**
     * Runs the probes with at most `$width` clones in flight and returns their
     * outcomes in the order the list declares.
     *
     * This is the sibling bench's pool, in the same shape and over the same
     * primitives, and one width of one is what "sequential" means here — there
     * is no second path to keep in step. What that buys, and what an earlier
     * fork-based scheduler of its own did not have: the stop flag is consulted
     * every turn, so an interrupt reaches a parallel run; the clones stay
     * registered in this process, so `Scratch::removeAll()` can reach them;
     * and a child that dies is read through `Child`, whose exit code reaches
     * the outcome instead of being inferred from a missing file.
     *
     * Order is restored rather than observed. The report has to read the same
     * whatever the scheduling, or two runs cannot be compared — which is the
     * one thing this bench exists to allow.
     *
     * @param list<Probe> $probes
     *
     * @return list<Outcome>
     */
    private static function runPool(array $probes, string $repository, int $width): array
    {
        $total = \count($probes);
        $outcomes = [];
        $inFlight = [];
        $next = 0;
        $spokeAt = microtime(true);

        while ($next < $total || $inFlight !== []) {
            Shell::stopIfRequested();

            while ($next < $total && \count($inFlight) < $width) {
                $probe = $probes[$next];

                try {
                    $inFlight[$next] = self::launch($probe, $repository);
                } catch (Throwable $error) {
                    $outcomes[$next] = Outcome::refused($probe, $error->getMessage());
                }

                $spokeAt = microtime(true);
                ++$next;
            }

            Shell::poll();

            foreach ($inFlight as $index => $attempt) {
                if (!$attempt['child']->settled()) {
                    continue;
                }

                unset($inFlight[$index]);
                $outcomes[$index] = self::settle($attempt);
                self::report(\sprintf(
                    '[%d/%d] %s %s',
                    $index + 1,
                    $total,
                    $attempt['probe']->id,
                    $outcomes[$index]->asDeclared() ? 'as declared' : 'NOT as declared',
                ));
                $spokeAt = microtime(true);
            }

            if ($inFlight !== [] && microtime(true) - $spokeAt >= self::LIVENESS_INTERVAL_SECONDS) {
                self::report(\sprintf(
                    'in flight  %s',
                    implode(', ', array_map(
                        static fn(array $attempt): string => $attempt['probe']->id,
                        $inFlight,
                    )),
                ));
                $spokeAt = microtime(true);
            }
        }

        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * Clones, plants the breakage and starts that clone's suite without waiting
     * for it.
     *
     * The clone carries no `.git`: the suite needs no history, and a worktree's
     * `.git` is a pointer at the main repository rather than the isolation
     * `Scratch` describes.
     *
     * @return array{probe: Probe, scratch: Scratch, child: Child, log: string}
     */
    private static function launch(Probe $probe, string $repository): array
    {
        $scratch = Scratch::contentOf($repository);

        try {
            $probe->mutation->apply($scratch, $repository);
            $started = Suite::startIn($scratch);
        } catch (Throwable $error) {
            $scratch->remove();

            throw $error;
        }

        return [
            'probe' => $probe,
            'scratch' => $scratch,
            'child' => $started['child'],
            'log' => $started['log'],
        ];
    }

    /**
     * Reads one finished clone and throws it away, whatever it said.
     *
     * A scratch tree left behind is a hardlink farm pointing at the working
     * tree, so the removal belongs to a `finally` rather than to the happy
     * path.
     *
     * @param array{probe: Probe, scratch: Scratch, child: Child, log: string} $attempt
     */
    private static function settle(array $attempt): Outcome
    {
        try {
            $suite = Suite::of($attempt['child']->result(), $attempt['log']);
            $red = $suite->red();

            if ($red === [] && $suite->exit !== 0) {
                return Outcome::refused($attempt['probe'], \sprintf(
                    'PHPUnit exited %d with every case green, so it failed on something the log does not'
                    . ' record — a warning or a risky test. This probe measured nothing.',
                    $suite->exit,
                ));
            }

            return Outcome::of($attempt['probe'], $suite->names(), $red);
        } catch (Throwable $failure) {
            return Outcome::refused($attempt['probe'], $failure->getMessage());
        } finally {
            $attempt['scratch']->remove();
        }
    }

    /**
     * Progress goes to the error stream because the report goes to the other
     * one: a run redirected to a file has to yield the same bytes whether or
     * not anybody watched it.
     */
    private static function report(string $line): void
    {
        fwrite(\STDERR, '  ' . $line . "\n");
    }

    /**
     * `--jobs=<n>` or `--jobs=auto`; the default is the same measured quarter
     * the sibling bench uses.
     *
     * @param list<string> $arguments
     */
    private static function jobs(array $arguments): int
    {
        $requested = null;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--jobs=')) {
                $requested = substr($argument, 7);
            }
        }

        if ($requested === null || $requested === 'auto') {
            return self::defaultJobs();
        }

        if (!ctype_digit($requested) || (int) $requested < 1 || (int) $requested > self::JOB_CEILING) {
            throw new RuntimeException(\sprintf(
                '--jobs takes "auto" or a number between 1 and %d, not %s.',
                self::JOB_CEILING,
                $requested,
            ));
        }

        return (int) $requested;
    }

    /**
     * A quarter of the machine, never fewer than two — the floor is what makes
     * this a parallel bench on a small machine rather than a sequential one,
     * and it is why the quarter is a ceiling on the ratio and not a promise.
     *
     * `shell_exec` is disabled in some hardened builds, where reading it would
     * be a fatal error rather than an unknown core count.
     */
    private static function defaultJobs(): int
    {
        $reported = \function_exists('shell_exec')
            ? (int) shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null')
            : 0;

        return max(2, min(self::JOB_CEILING, intdiv($reported > 0 ? $reported : 8, 4)));
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private static function only(array $arguments): array
    {
        $only = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--only=')) {
                $only = [...$only, ...array_filter(
                    explode(',', substr($argument, 7)),
                    static fn(string $id): bool => $id !== '',
                )];

                continue;
            }

            if (str_starts_with($argument, '--jobs=')) {
                continue;
            }

            if (str_starts_with($argument, '--')) {
                throw new RuntimeException(\sprintf('Unknown option %s.', $argument));
            }
        }

        return array_values($only);
    }
}
