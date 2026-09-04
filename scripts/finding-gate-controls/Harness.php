<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\DeclaredFieldMoves;
use RuntimeException;
use Throwable;

/** Runs the controls and decides whether every one of them behaved as declared. */
/**
 * PASS means less than it did before a step declared a delta, and that has to be
 * readable rather than inferred.
 *
 * Before, every control's every failure was either required or explicitly
 * tolerated at a named surface. Now a red control additionally tolerates
 * `delta-mismatch`, `delta-stale` and `delta-overreach` on the surfaces the step
 * under test declares — and `surface-mismatch` there too, but only for a control
 * that replaced the declaration index itself. Those surfaces are not compared
 * for equality in the first place, so a control can no longer use them as
 * evidence either way. What PASS still asserts, unchanged: the positive control
 * is green with the step's declarations intact and byte-compared, every red
 * control produced its required class at its required surface, and no red
 * control produced anything else anywhere else.
 *
 * And one thing it asserts that it did not before: every toleration a control
 * declares was matched by something. A toleration nothing matches states a blast
 * radius nobody measured, and it silently widens what the control will accept the
 * day the product starts producing it — the same defect `map-stale` and
 * `normalization-stale` fail for. See {@see Outcome::idleTolerations}.
 */
final class Harness
{
    private const DECLARED_DELTA_INDEX = 'finding-gate/declared-delta.tsv';

    /**
     * Every control in flight is a whole gate run: two passes over the corpus,
     * each spawning `bin/qmx --workers=0`. Fourteen at once would not make the
     * run fourteen times shorter — it would make every control slower and the
     * machine unusable, and a control whose gate is starved is a red that says
     * nothing about the mechanism it tests. The ceiling is here so `--jobs`
     * cannot ask for that either.
     */
    private const JOB_CEILING = 8;

    /** How long a run may print nothing before it says what it is waiting for. */
    private const LIVENESS_INTERVAL_SECONDS = 30.0;

    private function __construct(
        private readonly string $repository,
        private readonly string $reference,
        private readonly ?string $reportDirectory = null,
    ) {}

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $repository = \dirname(__DIR__, 2);
        $reference = null;
        $only = [];
        $forced = [];
        $reportDirectory = null;
        $watchLauncher = true;
        $jobs = null;

        foreach (\array_slice($argv, 1) as $argument) {
            $value = substr($argument, (int) strpos($argument, '=') + 1);

            if ($argument === '--help' || $argument === '-h') {
                echo self::usage();

                return 0;
            }

            if (str_starts_with($argument, '--reference=')) {
                $reference = $value;
            } elseif (str_starts_with($argument, '--only=')) {
                $only = self::list($value);
            } elseif ($argument === '--detached') {
                $watchLauncher = false;
            } elseif (str_starts_with($argument, '--jobs=')) {
                $jobs = (int) $value;

                if ($jobs < 1 || $jobs > self::JOB_CEILING) {
                    fwrite(\STDERR, \sprintf("--jobs must be between 1 and %d.\n", self::JOB_CEILING));

                    return 3;
                }
            } elseif (str_starts_with($argument, '--report-dir=')) {
                $reportDirectory = $value;
            } elseif (str_starts_with($argument, '--force-expect=')) {
                [$id, $failureClass] = array_pad(explode(':', $value, 2), 2, '');
                $forced[$id] = $failureClass;
            } else {
                fwrite(\STDERR, \sprintf("Unknown argument \"%s\".\n%s", $argument, self::usage()));

                return 3;
            }
        }

        if ($reference === null) {
            fwrite(\STDERR, "--reference=<git-ref> is required.\n" . self::usage());

            return 3;
        }

        // An interrupted run must leave nothing running and nothing behind. The
        // gate spawns its own children, so the scratch tree is only half the
        // debt: killing this process alone left a gate working invisibly for
        // seven minutes while a second run started beside it.
        //
        // Handlers therefore only raise a flag; Shell acts on it between pipe
        // reads, killing the whole descendant tree first. The shutdown function
        // is the backstop for the paths no handler sees — a fatal error, or an
        // exit from somewhere else.
        register_shutdown_function(static function (): void {
            Shell::terminateAll();
            Scratch::removeAll();
        });

        Shell::superviseFor($watchLauncher, static function (string $reason): void {
            fwrite(\STDERR, \sprintf(
                "\nInterrupted: %s. Killed the gate's process tree and removed the scratch tree.\n",
                $reason,
            ));
        });

        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            foreach ([\SIGINT, \SIGTERM, \SIGHUP] as $signal) {
                pcntl_signal($signal, static function (int $received): void {
                    Shell::requestStop('signal ' . $received);
                });
            }
        }

        try {
            return (new self($repository, $reference, $reportDirectory))->run(Controls::all($forced), $only, $jobs);
        } catch (Throwable $error) {
            fwrite(\STDERR, 'finding-gate-controls: ' . $error->getMessage() . "\n");

            return 3;
        } finally {
            Scratch::removeAll();
        }
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(
            explode(',', $value),
            static fn(string $item): bool => $item !== '',
        ));
    }

    private static function usage(): string
    {
        return <<<'TEXT'
            Usage: php scripts/finding-gate-controls.php --reference=<git-ref> [options]

              --reference=<git-ref>       Passed to the gate as the tree to compare against. Required.
              --only=<a,b>                Run these controls only. Default: all.
              --jobs=<n>                  How many controls run at a time. Default: a quarter of the machine's
                                          processors, at least 2 and at most 8. One control is one gate,
                                          and one gate is one `bin/qmx --workers=0` at a time, so a quarter of
                                          the cores leaves the machine usable and the clones room to copy.
              --report-dir=<path>         Keep each control's gate report as <path>/<control>.json, which is
                                          where the failure detail lives when a control misbehaves.
              --force-expect=<id>:<class> Replace a control's declared failure class, to show that a
                                          wrong expectation fails the harness. Not for regular runs.
              --detached                  Do not stop when the launching process disappears. By default it
                                          does: an interrupt that reaches only `composer` once left the gate
                                          running invisibly for seven minutes. Pass this to outlive a
                                          launcher on purpose.

            Each control clones this working tree — git-listed content and `vendor/` hardlinked,
            `.git` copied, everything git ignores left out — plants one breakage, runs THAT clone's
            own gate, and asserts the exit code and the failure class. The clone is what makes the
            harness survive Ш5's rewrite of the comparator.
            TEXT;
    }

    /**
     * @param list<Control> $controls
     * @param list<string> $only
     */
    private function run(array $controls, array $only, ?int $jobs): int
    {
        $selected = array_values(array_filter(
            $controls,
            static fn(Control $control): bool => $only === [] || \in_array($control->id, $only, true),
        ));

        if ($selected === []) {
            throw new RuntimeException('No control selected.');
        }

        $declaredSurfaces = $this->declaredSurfaces();

        // Before the first clone: an expectation pinned to a surface this
        // repository declares a delta for can never be met, and a twenty-minute
        // run is a poor way to hear it. See Control::assertNotPinnedToDeclaredDelta().
        foreach ($selected as $control) {
            $control->assertNotPinnedToDeclaredDelta($declaredSurfaces, self::replacesDeclaration($control));
        }

        $targets = self::mutationTargets($selected);
        $before = $this->workingTreeState();
        $beforeTargets = $this->targetDigests($targets);
        $width = min($jobs ?? $this->defaultJobs(), \count($selected));
        printf(
            "finding-gate controls — reference=%s, %d control(s), %d at a time\n\n",
            $this->reference,
            \count($selected),
            $width,
        );

        $outcomes = $this->runPool($selected, $width);

        echo "\n" . self::table($outcomes);
        $after = $this->workingTreeState();

        if ($before !== $after) {
            fwrite(\STDERR, "\n" . self::treeChangedMessage($targets, $beforeTargets, $this->targetDigests($targets)));

            return 2;
        }

        $failed = array_values(array_filter($outcomes, static fn(Outcome $outcome): bool => !$outcome->asDeclared));
        printf(
            "\n  %s — %d of %d control(s) behaved as declared; the working tree is unchanged.\n",
            $failed === [] ? 'PASS' : 'FAIL',
            \count($outcomes) - \count($failed),
            \count($outcomes),
        );

        return $failed === [] ? 0 : 1;
    }

    /**
     * Runs the selected controls with at most `$width` in flight, and returns
     * their outcomes in the order the controls were declared.
     *
     * Order is restored rather than observed: the summary table, the failure
     * list and the verdict must read the same as they did when the controls ran
     * one after another, or a run cannot be compared with the last one. What
     * arrives out of order is the progress lines, and each one carries its
     * control's declared position.
     *
     * @param list<Control> $controls
     *
     * @return list<Outcome>
     */
    private function runPool(array $controls, int $width): array
    {
        $total = \count($controls);
        $outcomes = [];
        $inFlight = [];
        $next = 0;
        $spokeAt = microtime(true);

        while ($next < $total || $inFlight !== []) {
            Shell::stopIfRequested();

            while ($next < $total && \count($inFlight) < $width) {
                $control = $controls[$next];
                printf("  [%d/%d] %s … started\n", $next + 1, $total, $control->id);

                try {
                    $inFlight[$next] = $this->launch($control);
                } catch (Throwable $error) {
                    $outcomes[$next] = Outcome::crashed($control, $error->getMessage());
                    self::announce($next, $total, $outcomes[$next], 0.0);
                }

                $spokeAt = microtime(true);
                ++$next;
            }

            Shell::poll();

            foreach ($inFlight as $index => $attempt) {
                if (!$attempt['child']->settled()) {
                    continue;
                }

                $elapsed = $attempt['child']->age();
                unset($inFlight[$index]);
                $outcomes[$index] = $this->settle($attempt);
                self::announce($index, $total, $outcomes[$index], $elapsed);
                $spokeAt = microtime(true);
            }

            if ($inFlight !== [] && microtime(true) - $spokeAt >= self::LIVENESS_INTERVAL_SECONDS) {
                printf("      in flight  %s\n", self::liveness($inFlight, $total));
                $spokeAt = microtime(true);
            }
        }

        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * Clones, plants the breakage and starts that clone's own gate, without
     * waiting for it.
     *
     * The clone blocks the pool for its ten seconds, which is why the children
     * already running are drained by {@see Shell::poll()} from inside every
     * blocking call rather than by a loop of their own.
     *
     * @return array{control: Control, scratch: Scratch, child: Child, report: string, survivors: array<string, string>, tracked: array<string, string>}
     */
    private function launch(Control $control): array
    {
        $scratch = Scratch::cloneOf($this->repository);

        try {
            $control->mutation->apply($scratch, $this->repository);
            $report = \dirname($scratch->tree) . '/report.json';
            $survivors = self::digestsOf($scratch, $control->unchangedAfterRun);
            $tracked = self::digestsOf($this->repository, $control->restoredAfterRun);
            $child = Shell::start(
                [
                    \PHP_BINARY,
                    $scratch->path('scripts/finding-gate.php'),
                    '--candidate=' . $scratch->tree,
                    '--reference=' . $this->reference,
                    '--report=' . $report,
                    ...$control->gateArguments,
                ],
                $scratch->tree,
            );
        } catch (Throwable $error) {
            $scratch->remove();

            throw $error;
        }

        return [
            'control' => $control,
            'scratch' => $scratch,
            'child' => $child,
            'report' => $report,
            'survivors' => $survivors,
            'tracked' => $tracked,
        ];
    }

    /**
     * The state of the paths a control declares its run may not touch, taken
     * after the mutation is planted so that what is compared is the run's own
     * effect and nothing else.
     *
     * A directory is digested by its file list *and* its contents: a write mode
     * that deletes the diff directory and writes it back identically has still
     * written, and the only reason to care is that the run said it had not.
     *
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private static function digestsOf(Scratch|string $root, array $paths): array
    {
        $digests = [];

        foreach ($paths as $path) {
            $digests[$path] = self::digestOf($root instanceof Scratch ? $root->path($path) : $root . '/' . $path);
        }

        return $digests;
    }

    private static function digestOf(string $absolute): string
    {
        if (is_file($absolute)) {
            return 'file:' . (string) hash_file('sha256', $absolute);
        }

        if (!is_dir($absolute)) {
            return 'absent';
        }

        $entries = scandir($absolute);
        $parts = [];

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $parts[] = $entry . "\0" . self::digestOf($absolute . '/' . $entry);
        }

        sort($parts);

        return 'dir:' . hash('sha256', implode("\0", $parts));
    }

    /** @param array{control: Control, scratch: Scratch, child: Child, report: string, survivors: array<string, string>, tracked: array<string, string>} $attempt */
    private function settle(array $attempt): Outcome
    {
        try {
            $run = $attempt['child']->result();
            $this->keepReport($attempt['control'], $attempt['report']);

            return Outcome::of(
                $attempt['control'],
                $run,
                $attempt['report'],
                $this->declaredSurfaces(),
                self::replacesDeclaration($attempt['control']),
                self::touched($attempt['scratch'], $attempt['survivors']),
                self::touched($attempt['scratch'], $attempt['tracked']),
                $this->declaredFieldMoveCount(),
            );
        } catch (Throwable $error) {
            return Outcome::crashed($attempt['control'], $error->getMessage());
        } finally {
            $attempt['scratch']->remove();
        }
    }

    /**
     * The paths whose content in the scratch tree is not the digest it was held
     * to — the survivors the run changed after all, or the declarations it
     * failed to put back.
     *
     * @param array<string, string> $before
     *
     * @return list<string>
     */
    private static function touched(Scratch $scratch, array $before): array
    {
        $touched = [];

        foreach ($before as $path => $digest) {
            if (self::digestOf($scratch->path($path)) !== $digest) {
                $touched[] = $path;
            }
        }

        return $touched;
    }

    private static function announce(int $index, int $total, Outcome $outcome, float $elapsed): void
    {
        printf(
            "  [%d/%d] %s … %s (%s)\n",
            $index + 1,
            $total,
            $outcome->control->id,
            $outcome->asDeclared ? 'as declared' : 'NOT AS DECLARED',
            self::duration($elapsed),
        );
    }

    /**
     * What the run is waiting for, per child rather than per stream: a quiet
     * control is invisible in a merged output stream, and with a pool the
     * merged stream is the only thing an onlooker would otherwise see.
     *
     * @param array<int, array{control: Control, scratch: Scratch, child: Child, report: string}> $inFlight
     */
    private static function liveness(array $inFlight, int $total): string
    {
        $parts = [];

        foreach ($inFlight as $index => $attempt) {
            $parts[] = \sprintf(
                '[%d/%d] %s %s, quiet %s, %d KiB',
                $index + 1,
                $total,
                $attempt['control']->id,
                self::duration($attempt['child']->age()),
                self::duration($attempt['child']->outputAge()),
                intdiv($attempt['child']->bytes(), 1024),
            );
        }

        return implode('; ', $parts);
    }

    private static function duration(float $seconds): string
    {
        return $seconds >= 60.0
            ? \sprintf('%dm%02ds', (int) ($seconds / 60), (int) $seconds % 60)
            : \sprintf('%.1fs', $seconds);
    }

    /**
     * A quarter of the machine's processors, floored at two.
     *
     * One control in flight is one gate, and a gate runs `bin/qmx --workers=0`
     * — one busy core plus git and filesystem work. A quarter leaves room for
     * the clone bursts, for the machine to stay usable, and for the fact that
     * this is a developer's laptop and not a runner.
     */
    private function defaultJobs(): int
    {
        return max(2, min(self::JOB_CEILING, intdiv($this->processorCount(), 4)));
    }

    private function processorCount(): int
    {
        foreach ([['getconf', '_NPROCESSORS_ONLN'], ['sysctl', '-n', 'hw.ncpu']] as $command) {
            $result = Shell::run($command, $this->repository);
            $count = (int) trim($result['stdout']);

            if ($result['exit'] === 0 && $count > 0) {
                return $count;
            }
        }

        return 4;
    }

    /** Whether this control plants a declaration of its own over the repository's. */
    private static function replacesDeclaration(Control $control): bool
    {
        return \in_array(self::DECLARED_DELTA_INDEX, $control->mutation->relativePaths(), true);
    }

    /**
     * The surfaces the step under test declares a delta for.
     *
     * A red control cannot use them as evidence about the mechanism it tests,
     * so failures that are statements about the declaration are tolerated
     * there — bounded by class, and for `surface-mismatch` by whether this
     * control replaced the index. See {@see Outcome::isDeclarationNoise()}.
     *
     * Read through the gate's own loader, not a second parser of the same file.
     * The hand-rolled one this replaces was laxer in the dangerous direction:
     * it accepted a row whose `reason` was still `?` and a row naming a diff
     * file that does not exist, both of which the gate refuses, and a wider set
     * of declared surfaces silently widens the toleration. Read from the
     * repository, never from the scratch tree, because a control that plants its
     * own declaration replaces the index and the step's own surfaces have to
     * stay known even then.
     *
     * @return list<string>
     */
    private function declaredSurfaces(): array
    {
        if (!is_file($this->repository . '/' . self::DECLARED_DELTA_INDEX)) {
            return [];
        }

        return DeclaredDelta::load($this->repository . '/finding-gate')->surfaces();
    }

    /**
     * How many moves of a compared field this repository licenses, read the same
     * way and for the same reason as the declared surfaces: a green control has
     * to be held to the licences the repository already states and to no more,
     * or a control could go green because a licence absorbed its mutation.
     */
    private function declaredFieldMoveCount(): int
    {
        return DeclaredFieldMoves::load($this->repository . '/finding-gate')->count();
    }

    private function keepReport(Control $control, string $report): void
    {
        if ($this->reportDirectory === null || !is_file($report)) {
            return;
        }

        if (!is_dir($this->reportDirectory) && !@mkdir($this->reportDirectory, 0o777, true)) {
            throw new RuntimeException(\sprintf('Cannot create %s.', $this->reportDirectory));
        }

        Shell::replace($this->reportDirectory . '/' . $control->id . '.json', Shell::read($report));
    }

    /**
     * The whole point of the hardlink footgun: if a mutation ever writes through
     * a link, this string moves and the harness refuses to report success.
     *
     * It moves for another reason too — the repository being edited while the
     * harness runs — and no digest of the whole tree can tell the two apart.
     * That is what `targetDigests()` is for.
     */
    private function workingTreeState(): string
    {
        $parts = [];

        foreach ([['git', 'status', '--porcelain'], ['git', 'diff'], ['git', 'diff', '--cached']] as $command) {
            $parts[] = Shell::mustRun($command, $this->repository);
        }

        return hash('sha256', implode("\0", $parts));
    }

    /**
     * The files the selected mutations edit, i.e. the only paths in the real
     * repository a write-through could plausibly come from.
     *
     * @param list<Control> $controls
     *
     * @return list<string>
     */
    private static function mutationTargets(array $controls): array
    {
        $paths = [];

        foreach ($controls as $control) {
            foreach ($control->mutation->relativePaths() as $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function targetDigests(array $paths): array
    {
        $digests = [];

        foreach ($paths as $path) {
            $absolute = $this->repository . '/' . $path;
            $digests[$path] = is_file($absolute) ? (string) hash_file('sha256', $absolute) : 'absent';
        }

        return $digests;
    }

    /**
     * Names both causes, because the guard cannot know which one fired — and
     * says which one the evidence points at. A moved mutation target is a write
     * through a hardlink and nothing else; every target intact means the change
     * came from somewhere the harness never writes, an edit to the repository
     * while it ran being by far the likeliest.
     *
     * @param list<string> $targets
     * @param array<string, string> $before
     * @param array<string, string> $after
     */
    private static function treeChangedMessage(array $targets, array $before, array $after): string
    {
        $moved = array_values(array_filter(
            $targets,
            static fn(string $path): bool => ($before[$path] ?? null) !== ($after[$path] ?? null),
        ));

        if ($moved !== []) {
            return \sprintf(
                "The working tree changed while the harness ran, and a file a mutation edits moved: %s.\n"
                . "That is a write through a hardlink. The working tree is corrupted: restore those files"
                . " and trust nothing above.\n",
                implode(', ', $moved),
            );
        }

        return \sprintf(
            "The working tree changed while the harness ran. Two things cause that and the guard cannot tell"
            . " them apart:\n"
            . "  - the repository was edited while the harness ran — nothing is wrong with the harness;\n"
            . "  - a mutation wrote through a hardlink — the working tree is corrupted.\n"
            . "None of the %d file(s) the mutations edit moved (%s), which points at the first.\n"
            . "Compare `git status` against your own edits before trusting anything above.\n",
            \count($targets),
            $targets === [] ? 'none selected' : implode(', ', $targets),
        );
    }

    /** @param list<Outcome> $outcomes */
    private static function table(array $outcomes): string
    {
        $lines = [];

        foreach ($outcomes as $outcome) {
            $control = $outcome->control;
            $observed = $outcome->observedClasses();
            $lines[] = \sprintf('  %s  [%s]', $control->id, $outcome->asDeclared ? 'AS DECLARED' : 'FAILED CONTROL');
            $lines[] = '      subject    ' . $control->subject;
            $lines[] = '      mutation   ' . $control->mutation->label();
            $lines[] = '      expected   ' . $control->expectationLabel();
            $lines[] = \sprintf(
                '      observed   exit %d; %s',
                $outcome->exitCode,
                $observed === [] ? 'no failures' : implode(', ', $observed),
            );

            foreach ([
                'matched' => $outcome->matched,
                'tolerated' => $outcome->tolerated,
                'unexpected' => $outcome->unexpected,
                'idle' => $outcome->idleTolerations,
            ] as $label => $failures) {
                foreach ($failures as $failure) {
                    $lines[] = \sprintf('      %-10s %s', $label, $failure);
                }
            }

            foreach ($outcome->reasons as $reason) {
                $lines[] = '      verdict    ' . $reason;
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
