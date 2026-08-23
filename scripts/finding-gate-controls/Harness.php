<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use RuntimeException;
use Throwable;

/** Runs the controls and decides whether every one of them behaved as declared. */
final class Harness
{
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

        foreach (\array_slice($argv, 1) as $argument) {
            $value = substr($argument, (int) strpos($argument, '=') + 1);

            if ($argument === '--help' || $argument === '-h') {
                echo self::usage();

                return 0;
            }

            if (str_starts_with($argument, '--reference=')) {
                $reference = $value;
            } elseif (str_starts_with($argument, '--only=')) {
                $only = array_values(array_filter(explode(',', $value)));
            } elseif ($argument === '--detached') {
                $watchLauncher = false;
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
            return (new self($repository, $reference, $reportDirectory))->run(Controls::all($forced), $only);
        } catch (Throwable $error) {
            fwrite(\STDERR, 'finding-gate-controls: ' . $error->getMessage() . "\n");

            return 3;
        } finally {
            Scratch::removeAll();
        }
    }

    private static function usage(): string
    {
        return <<<'TEXT'
            Usage: php scripts/finding-gate-controls.php --reference=<git-ref> [options]

              --reference=<git-ref>       Passed to the gate as the tree to compare against. Required.
              --only=<a,b>                Run these controls only. Default: all.
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
    private function run(array $controls, array $only): int
    {
        $selected = array_values(array_filter(
            $controls,
            static fn(Control $control): bool => $only === [] || \in_array($control->id, $only, true),
        ));

        if ($selected === []) {
            throw new RuntimeException('No control selected.');
        }

        $targets = self::mutationTargets($selected);
        $before = $this->workingTreeState();
        $beforeTargets = $this->targetDigests($targets);
        printf("finding-gate controls — reference=%s, %d control(s)\n\n", $this->reference, \count($selected));

        $outcomes = [];

        foreach ($selected as $index => $control) {
            Shell::stopIfRequested();
            printf("  [%d/%d] %s … ", $index + 1, \count($selected), $control->id);
            $outcome = $this->execute($control);
            $outcomes[] = $outcome;
            printf("%s\n", $outcome->asDeclared ? 'as declared' : 'NOT AS DECLARED');
        }

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

    private function execute(Control $control): Outcome
    {
        $scratch = Scratch::cloneOf($this->repository);

        try {
            $control->mutation->apply($scratch, $this->repository);
            $report = \dirname($scratch->tree) . '/report.json';
            $run = Shell::run(
                [
                    \PHP_BINARY,
                    $scratch->path('scripts/finding-gate.php'),
                    '--candidate=' . $scratch->tree,
                    '--reference=' . $this->reference,
                    '--report=' . $report,
                ],
                $scratch->tree,
            );

            $this->keepReport($control, $report);

            return Outcome::of($control, $run, $report);
        } catch (Throwable $error) {
            return Outcome::crashed($control, $error->getMessage());
        } finally {
            $scratch->remove();
        }
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
            if (!$control->mutation->isEmpty()) {
                $paths[] = $control->mutation->relativePath;
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
            $lines[] = '      mutation   ' . ($control->mutation->isEmpty()
                ? 'none'
                : $control->mutation->relativePath . ' — ' . $control->mutation->description);
            $lines[] = '      expected   ' . $control->expectationLabel();
            $lines[] = \sprintf(
                '      observed   exit %d; %s',
                $outcome->exitCode,
                $observed === [] ? 'no failures' : implode(', ', $observed),
            );

            foreach (['matched' => $outcome->matched, 'tolerated' => $outcome->tolerated, 'unexpected' => $outcome->unexpected] as $label => $failures) {
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
