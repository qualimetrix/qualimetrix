<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What the gate takes from the machine and gives back: files it writes and removes, child processes,
 * reference checkouts and the scratch registry that releases them when a run is stopped.
 */
final class SelfTestResources extends SelfTestGroup
{
    /**
     * Every tracked declaration this gate writes is written through
     * {@see Fs::write()}, and the controls harness clones the working tree by
     * **hardlinking** its content. A write in place therefore lands in the
     * developer's own repository: measured on 2026-09-04, one control run left
     * this repository's `declared-delta.tsv` holding thirteen rows derived from
     * a mutated clone.
     */
    public function writesNeverFollowHardlinks(): void
    {
        $root = Fs::temporaryDirectory('self-test-hardlink-');
        Fs::write($root . '/original', "before\n");
        $this->assert(link($root . '/original', $root . '/clone'), 'the hardlink case can be set up at all');
        Fs::write($root . '/clone', "after\n");
        $this->same("before\n", Fs::read($root . '/original'), 'writing a hardlinked file does not write through the link');
    }

    /**
     * The gate deletes reference worktrees and scratch directories, so a delete
     * that walks a symlink deletes outside the tree it was given.
     */
    public function removal(): void
    {
        $root = Fs::temporaryDirectory('self-test-removal-');
        $outside = $root . '/outside';
        mkdir($outside);
        Fs::write($outside . '/keep.txt', 'keep');
        $victim = $root . '/victim';
        mkdir($victim);
        symlink($outside, $victim . '/link');

        Fs::removeRecursively($victim);

        $this->assert(!is_dir($victim), 'a directory handed to the removal is gone');
        $this->assert(is_file($outside . '/keep.txt'), 'a symlinked directory is unlinked, not walked into and emptied');

        Fs::removeRecursively($root);
    }

    /** A child that closes its pipes before hanging is still covered by the deadline. */
    public function processDeadline(): void
    {
        $startedAt = microtime(true);
        $message = null;

        try {
            Process::run(
                [\PHP_BINARY, '-r', 'fclose(STDOUT); fclose(STDERR); usleep(2000000);'],
                $this->candidateRoot,
                0.1,
            );
        } catch (GateError $error) {
            $message = $error->getMessage();
        }

        $this->assert(
            $message !== null && str_contains($message, 'Timed out after 0.1 seconds'),
            'a process that closes both pipes before hanging is refused by the deadline',
        );
        $this->assert(
            microtime(true) - $startedAt < 1.5,
            'the process deadline does not fall through to an unbounded proc_close',
        );
    }

    /** A child spawned by a TERM handler remains inside the supervised group. */
    public function processGroupTermination(): void
    {
        $root = Fs::temporaryDirectory('self-test-process-group-');
        $pidPath = $root . '/late-child.pid';
        $script = <<<'SH'
            trap 'sleep 30 & echo $! > "$1"; exit 0' TERM
            while :; do sleep 1; done
            SH;

        try {
            try {
                Process::run(['/bin/sh', '-c', $script, 'finding-gate-self-test', $pidPath], $this->candidateRoot, 0.1);
            } catch (GateError) {
                // The deadline is the trigger under test.
            }

            $deadline = microtime(true) + 0.5;
            while (!is_file($pidPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $pid = (int) (is_file($pidPath) ? Fs::read($pidPath) : '0');
            $this->assert($pid > 1, 'the TERM handler spawned the late child used by the process-group control');

            $deadline = microtime(true) + 0.5;
            while ($pid > 1 && @posix_kill($pid, 0) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assert($pid < 2 || !@posix_kill($pid, 0), 'the process deadline leaves no late child behind');
        } finally {
            Fs::removeRecursively($root);
        }
    }

    /**
     * A run killed *while* `git worktree add` is running still gives the
     * checkout back.
     *
     * This is the one case that judges the order inside
     * {@see ReferenceTree::create()} rather than its result: the release is
     * held before the checkout is asked for, because the path is known in
     * advance and a half-finished add is registered all the same. Move that
     * `hold()` back below the add — the shape this replaced — and every other
     * case here stays green, because every other case signals a run that had
     * already returned from `create()`.
     *
     * `git` is shadowed by a wrapper that adds the worktree and then sleeps, so
     * the signal lands with the registration written and the command still
     * running. Shadowing rather than timing, because a race is not a test.
     */
    public function releasedWhenKilledDuringCheckout(): void
    {
        $repository = $this->throwawayRepository(withVendor: true);

        try {
            $shadow = Fs::temporaryDirectory('self-test-slow-git-');
            Fs::write(
                $shadow . '/git',
                "#!/bin/sh\ncase \" $* \" in\n  *\" worktree add \"*) /usr/bin/git \"$@\" && sleep 30 ;;\n"
                . "  *) exec /usr/bin/git \"$@\" ;;\nesac\n",
            );
            chmod($shadow . '/git', 0o755);

            $child = self::startHoldingChild($repository, fatal: false, pathPrefix: $shadow, announceBeforeCheckout: true, diagnostic: $diagnostic);

            if ($child === null) {
                $this->failures[] = 'the mid-checkout case starts a run that reaches git worktree add'
                    . ($diagnostic === '' ? '' : ' (stderr: ' . $diagnostic . ')');

                return;
            }

            // Wait for the registration to exist, which is what makes this the
            // mid-add window rather than the before-add one.
            $deadline = microtime(true) + 30;

            while (self::registeredWorktrees($repository) === [] && microtime(true) < $deadline) {
                usleep(50_000);
            }

            $this->assert(
                self::registeredWorktrees($repository) !== [],
                'the mid-checkout case reached a registered checkout before signalling',
            );

            posix_kill($child['pid'], \SIGTERM);
            self::waitForExit($child);

            $this->same([], self::registeredWorktrees($repository), 'a run killed during the checkout still deregisters it');
        } finally {
            self::discard($repository);
        }
    }

    /** The registry's own shape: reverse order, run once, and one failure does not strand the rest. */
    public function scratchRegistry(): void
    {
        $released = [];
        Scratch::hold(static function () use (&$released): void {
            $released[] = 'first';
        });
        Scratch::hold(static function (): void {
            throw new GateError('(self-test) a deliberate failure, from the case that proves one does not strand the rest');
        });
        $cancel = Scratch::hold(static function () use (&$released): void {
            $released[] = 'cancelled';
        });
        Scratch::hold(static function () use (&$released): void {
            $released[] = 'last';
        });
        $cancel();

        Scratch::releaseAll();
        Scratch::releaseAll();

        $this->same(['last', 'first'], $released, 'holdings are released in reverse, once, and a cancelled one is not');
    }

    /**
     * A creation that fails after the checkout exists still gives it back.
     *
     * Measured on the pre-fix tree: `installVendor()` sat outside the try that
     * guarded the vocabulary check above it, so this threw and left the checkout
     * registered in the repository it was taken from. The assertion on the
     * message is not decoration — without it the case passes when `create()`
     * fails at the vocabulary check instead, which always cleaned up and would
     * make this green while proving nothing.
     */
    public function referenceReleasedWhenCreationFails(): void
    {
        $repository = $this->throwawayRepository(withVendor: false);

        try {
            $message = null;

            try {
                ReferenceTree::create($repository, 'HEAD');
            } catch (GateError $error) {
                $message = $error->getMessage();
            }

            $this->assert(
                $message !== null && str_contains($message, 'has no vendor/'),
                'a reference tree with nothing to install is refused at the vendor step',
            );
            $this->same([], self::registeredWorktrees($repository), 'and the checkout it had already taken is given back');
        } finally {
            self::discard($repository);
        }
    }

    /**
     * The registration a killed `git worktree add` leaves is still released.
     *
     * `locked = initializing` is what git writes while a checkout is being
     * created, and an interrupted run now terminates its own git, so this is a
     * state the gate produces rather than one it might meet. `prune` skips a
     * locked entry and a single `--force` refuses it outright — measured on git
     * 2.55.0 — so releasing it takes the second `--force`, and nothing but a
     * case like this would notice if that were dropped.
     */
    public function lockedRegistrationIsStillReleased(): void
    {
        $repository = $this->throwawayRepository(withVendor: true);

        try {
            $tree = ReferenceTree::create($repository, 'HEAD');

            // The half that keeps the other half honest. Every assertion here
            // reads "nothing is registered", and a path comparison that can
            // never match satisfies all of them while seeing nothing — which is
            // what the release verification did until 2026-09-14, because
            // `sys_get_temp_dir()` hands out `/var/tmp/` and git prints
            // `/private/var/tmp`. A live checkout must be visible, or an empty
            // list means nothing.
            $this->same(
                [$tree->root],
                self::registeredWorktrees($repository),
                'a live reference checkout is visible to the path comparison these cases rely on',
            );

            $administrations = glob($repository . '/.git/worktrees/*');
            $locked = 0;

            foreach ($administrations === false ? [] : $administrations as $administration) {
                Fs::write($administration . '/locked', "initializing\n");
                ++$locked;
            }

            // Without this the case passes when git keeps its administration
            // somewhere else: nothing would be locked, and releasing an ordinary
            // registration would be mistaken for releasing a locked one.
            $this->assert($locked > 0, 'the locked-registration case actually locked a registration');

            $tree->remove();

            $this->same([], self::registeredWorktrees($repository), 'a locked reference registration is released, not reported as gone');
            $this->assert(!is_dir($tree->root), 'and its directory goes with it');
        } catch (GateError $error) {
            $this->failures[] = 'a locked reference registration is released (' . $error->getMessage() . ')';
        } finally {
            self::discard($repository);
        }
    }

    /**
     * The row that matters: a run that is signalled, or that dies of a PHP
     * fatal, keeps nothing.
     *
     * The child is started with a bare `proc_open` rather than through
     * {@see ProcessHandle}, whose launcher would put a `php -r` in between — the
     * signal has to reach the run itself, and the exit code has to be read from
     * it. Each child announces the exact paths it took, and only those are
     * asserted on: "no finding-gate-* anywhere" would be reddened by any other
     * gate running on the same machine.
     */
    public function interruptedRunReleasesEverything(): void
    {
        foreach ([['SIGINT', \SIGINT, 130], ['SIGTERM', \SIGTERM, 143], ['fatal', null, null]] as [$name, $signal, $expected]) {
            $repository = $this->throwawayRepository(withVendor: true);

            try {
                $child = self::startHoldingChild($repository, $signal === null, diagnostic: $diagnostic);

                if ($child === null) {
                    $this->failures[] = \sprintf('the %s case starts a run that takes a reference tree', $name)
                        . ($diagnostic === '' ? '' : ' (stderr: ' . $diagnostic . ')');

                    continue;
                }

                if ($signal !== null) {
                    posix_kill($child['pid'], $signal);
                }

                $exit = self::waitForExit($child);

                foreach ($child['paths'] as $path) {
                    $this->assert(!is_dir($path), \sprintf('%s: %s is released', $name, basename($path)));
                }

                $this->same([], self::registeredWorktrees($repository), $name . ': the reference checkout is deregistered');

                if ($expected !== null) {
                    $this->same($expected, $exit, $name . ': the run exits 128 + the signal');
                }
            } finally {
                self::discard($repository);
            }
        }
    }

    /**
     * Starts a run that takes a reference tree and then blocks inside a poll,
     * which is where an interrupt is acted on; or fatals, which is where only
     * the shutdown backstop is.
     *
     * Reads two announcement lines from stdout under a deadline and must
     * return the child *alive*, with its stdout handle, while the child runs
     * `Process::run(['/bin/sleep', '30'], ...)` — {@see ChildProcess::run()}
     * waits for exit and cannot stand in here. Stderr is therefore never a
     * pipe: a child that writes more to it than a caller reads before the two
     * announcement lines arrive would otherwise block the parent's stdout
     * read forever instead of failing this case. It goes to a file inside the
     * same scratch directory as `$file`, so {@see Scratch}'s own release
     * cleans it up with everything else this call allocated — nothing here
     * unlinks it directly.
     *
     * @param-out string $diagnostic the child's stderr, captured whether this
     *                               call succeeds or returns null, so a
     *                               caller reporting a failure is not left
     *                               guessing what the child said
     *
     * @return array{pid: int, handle: resource, stdout: resource, paths: list<string>}|null
     */
    private static function startHoldingChild(
        string $repository,
        bool $fatal,
        ?string $pathPrefix = null,
        bool $announceBeforeCheckout = false,
        ?string &$diagnostic = null,
    ): ?array {
        $diagnostic = '';
        $source = <<<'PHP'
            <?php
            namespace QmxFindingGate;
            require $argv[1] . '/classes.php';
            try {
                $run = Fs::temporaryDirectory('finding-gate-run-');

                // Announced before the checkout when the caller is testing the
                // window *inside* create(): after it there is nothing to read.
                if ($argv[4] === 'early') {
                    echo $run, "\n", $run, "\n", "ready\n";
                }

                $tree = ReferenceTree::create($argv[2], 'HEAD');

                if ($argv[4] !== 'early') {
                    echo $run, "\n", \dirname($tree->root), "\n", "ready\n";
                }
                if ($argv[3] === 'fatal') {
                    throw new \Error('a deliberate fatal, after the run took everything it takes');
                }
                Process::run(['/bin/sleep', '30'], $argv[2]);
            } catch (GateError $error) {
                fwrite(STDERR, $error->getMessage() . "\n");
                exit(Interruption::exitCode() ?? 3);
            }
            PHP;

        $directory = Fs::temporaryDirectory('self-test-interrupt-');
        $file = $directory . '/run.php';
        $stderrFile = $directory . '/stderr.log';
        Fs::write($file, $source);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']];
        $environment = null;

        if ($pathPrefix !== null) {
            $environment = ['PATH' => $pathPrefix . ':' . (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')];
        }

        $handle = proc_open(
            [
                \PHP_BINARY,
                $file,
                __DIR__,
                $repository,
                $fatal ? 'fatal' : 'block',
                $announceBeforeCheckout ? 'early' : 'late',
            ],
            $descriptors,
            $pipes,
            $repository,
            $environment,
        );

        if (!\is_resource($handle)) {
            $diagnostic = self::readHeldChildStderr($stderrFile);

            return null;
        }

        stream_set_blocking($pipes[1], false);
        $paths = [];
        $deadline = microtime(true) + 30;

        while (\count($paths) < 2 && microtime(true) < $deadline) {
            $line = fgets($pipes[1]);

            if ($line === false) {
                usleep(20_000);

                continue;
            }

            if (trim($line) !== 'ready' && trim($line) !== '') {
                $paths[] = trim($line);
            }
        }

        $pid = proc_get_status($handle)['pid'];

        if (\count($paths) !== 2) {
            // A child that never announced is a child nobody is holding: killed
            // here, or the case returns having left a run alive with a checkout.
            @posix_kill($pid, \SIGKILL);
            fclose($pipes[1]);
            proc_close($handle);
            $diagnostic = self::readHeldChildStderr($stderrFile);

            return null;
        }

        $diagnostic = self::readHeldChildStderr($stderrFile);

        return ['pid' => $pid, 'handle' => $handle, 'stdout' => $pipes[1], 'paths' => $paths];
    }

    /** Best-effort: a missing or unreadable file is not itself a failure worth reporting here. */
    private static function readHeldChildStderr(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $contents = @file_get_contents($path);

        return $contents === false ? '' : trim($contents);
    }

    /** @param array{pid: int, handle: resource, stdout: resource, paths: list<string>} $child */
    private static function waitForExit(array $child): int
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($child['handle']);

            if ($status['running'] === false) {
                fclose($child['stdout']);
                proc_close($child['handle']);

                return $status['exitcode'];
            }

            usleep(20_000);
        }

        posix_kill($child['pid'], \SIGKILL);
        fclose($child['stdout']);
        proc_close($child['handle']);

        return -1;
    }

    /**
     * A repository to take a reference checkout from, which is never the
     * developer's: a case that fails halfway would otherwise leave behind
     * exactly the registration these cases exist to refuse.
     *
     * It carries the two files {@see MetricVocabulary} reads, committed and with
     * the literal syntax that class matches, or `create()` stops at the
     * vocabulary check — a path that already cleaned up before any of this.
     */
    private function throwawayRepository(bool $withVendor): string
    {
        $root = Fs::temporaryDirectory('self-test-reference-repository-');
        $contract = $root . '/src/Analysis/Evidence/Measurement/Contract';
        Fs::write($contract . '/AggregationStrategy.php', "<?php\n\nenum AggregationStrategy: string\n{\n    case Sum = 'sum';\n}\n");
        Fs::write($contract . '/MetricName.php', "<?php\n\nfinal class MetricName\n{\n    public const string CCN = 'ccn';\n}\n");

        if ($withVendor) {
            Fs::write($root . '/vendor/autoload.php', "<?php\n");
        }

        foreach ([
            ['git', 'init', '--quiet'],
            ['git', 'add', '--all'],
            ['git', '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'fixture'],
        ] as $command) {
            $result = Process::run($command, $root);

            if ($result['exit'] !== 0) {
                throw new GateError(\sprintf("Cannot build the throwaway repository:\n%s", $result['stderr']));
            }
        }

        return $root;
    }

    /**
     * Every worktree the repository has registered beyond itself.
     *
     * Asked of git rather than of the filesystem: what these cases are about is
     * the registration, and a directory that is gone while `git worktree list`
     * still names it is the defect, not the absence of one.
     *
     * @return list<string>
     */
    private static function registeredWorktrees(string $repository): array
    {
        $listed = Process::run(['git', '-C', $repository, 'worktree', 'list', '--porcelain'], $repository);
        $paths = [];

        foreach (explode("\n", $listed['stdout']) as $line) {
            if (str_starts_with($line, 'worktree ') && substr($line, 9) !== $repository) {
                $paths[] = substr($line, 9);
            }
        }

        return $paths;
    }

    /** Removes the fixture, and whatever a failing case left registered in it, without touching anything else. */
    private static function discard(string $repository): void
    {
        foreach (self::registeredWorktrees($repository) as $path) {
            Process::run(['git', '-C', $repository, 'worktree', 'remove', '--force', '--force', $path], $repository);
        }

        Fs::removeRecursively($repository);
    }
}
