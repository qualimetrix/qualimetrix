<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

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
            $jobs = self::jobs($arguments);
        } catch (RuntimeException $error) {
            fwrite(\STDERR, 'directive-audit-controls: ' . $error->getMessage() . "\n");

            return 3;
        }

        return Report::of(self::observeAll($probes, $repository, $jobs), $only !== [])->print();
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
     * One probe: clone, plant, run, read.
     *
     * The clone is thrown away whatever happens, including on a refusal — a
     * scratch tree left behind is a hardlink farm pointing at the developer's
     * working tree.
     */
    private static function observe(Probe $probe, string $repository): Outcome
    {
        $scratch = Scratch::cloneOf($repository);

        try {
            // A worktree's `.git` is a pointer at the main repository, so the
            // clone's copy of it is not the isolation `Scratch` describes. The
            // suite needs no history, so the safe move is to leave it none.
            Shell::removeRecursively($scratch->path('.git'));

            $probe->mutation->apply($scratch, $repository);
            $suite = Suite::runIn($scratch->tree);
            $red = $suite->red();

            if ($red === [] && $suite->exit !== 0) {
                return Outcome::refused($probe, \sprintf(
                    'PHPUnit exited %d with every case green, so it failed on something the log does not'
                    . ' record — a warning or a risky test. This probe measured nothing.',
                    $suite->exit,
                ));
            }

            return Outcome::of($probe, $suite->names(), $red);
        } catch (Throwable $failure) {
            return Outcome::refused($probe, $failure->getMessage());
        } finally {
            $scratch->remove();
        }
    }

    /**
     * Runs the probes, `$jobs` of them at a time, and returns their outcomes in
     * the order the list declares rather than the order they finished.
     *
     * A probe is a clone, a mutation and a PHPUnit run over nine files, and it
     * shares nothing with its neighbours: measured at 11.3 CPU seconds and 0.94
     * cores, so the sequential bench left thirteen of fourteen cores idle.
     *
     * The parallel path forks around {@see observe()} rather than reimplementing
     * it, so both paths plant and read a breakage the same way. Forking happens
     * before the clone, which keeps `Scratch::$live` empty in the parent: each
     * child registers, and removes, only its own tree.
     *
     * A child that writes no outcome is a refusal, never a gap. It is the shape
     * an out-of-memory kill takes, and a bench that silently dropped such a
     * probe would report a smaller suite as a complete one.
     *
     * @param list<Probe> $probes
     *
     * @return list<Outcome>
     */
    private static function observeAll(array $probes, string $repository, int $jobs): array
    {
        if ($jobs === 1 || !\function_exists('pcntl_fork')) {
            return array_map(
                static fn(Probe $probe): Outcome => self::observe($probe, $repository),
                $probes,
            );
        }

        $directory = Shell::temporaryDirectory('directive-audit-controls-outcomes-');
        $outcomes = [];
        $running = [];

        try {
            foreach ($probes as $index => $probe) {
                while (\count($running) >= $jobs) {
                    self::reap($running, $outcomes, $directory, $probes);
                }

                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork a worker for the probe bench.');
                }

                if ($pid === 0) {
                    file_put_contents(
                        $directory . '/' . $index,
                        serialize(self::observe($probe, $repository)),
                    );

                    exit(0);
                }

                $running[$pid] = $index;
            }

            while ($running !== []) {
                self::reap($running, $outcomes, $directory, $probes);
            }
        } finally {
            if (\function_exists('posix_kill')) {
                foreach (array_keys($running) as $pid) {
                    @posix_kill($pid, \defined('SIGTERM') ? \SIGTERM : 15);
                }
            }

            Shell::removeRecursively($directory);
        }

        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * @param array<int, int> $running pid => index of the probe it carries
     * @param array<int, Outcome> $outcomes
     * @param list<Probe> $probes
     */
    private static function reap(array &$running, array &$outcomes, string $directory, array $probes): void
    {
        $status = 0;
        $pid = pcntl_wait($status);

        if ($pid <= 0 || !isset($running[$pid])) {
            return;
        }

        $index = $running[$pid];
        unset($running[$pid]);
        $path = $directory . '/' . $index;
        $written = is_file($path) ? file_get_contents($path) : false;
        $outcome = $written === false ? false : @unserialize($written);

        if (!$outcome instanceof Outcome) {
            $outcomes[$index] = Outcome::refused($probes[$index], \sprintf(
                'The worker carrying this probe exited %d without writing an outcome, so it measured nothing.',
                pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
            ));

            return;
        }

        $outcomes[$index] = $outcome;
    }

    /**
     * `--jobs=auto` leaves one core to the rest of the machine; an explicit
     * number is taken as given. The default is one, so a bench run by hand
     * behaves as it always did unless it is asked otherwise.
     *
     * @param list<string> $arguments
     */
    private static function jobs(array $arguments): int
    {
        $requested = '1';

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--jobs=')) {
                $requested = substr($argument, 7);
            }
        }

        if ($requested === 'auto') {
            $reported = (int) shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null');

            return max(1, ($reported > 0 ? $reported : 2) - 1);
        }

        if (!ctype_digit($requested) || (int) $requested < 1) {
            throw new RuntimeException(\sprintf('--jobs takes a positive number or "auto", not %s.', $requested));
        }

        return (int) $requested;
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
