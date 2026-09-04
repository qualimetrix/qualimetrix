<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use Closure;
use RuntimeException;

/**
 * Process and filesystem primitives, kept local so the harness owns them.
 *
 * Every child is *supervised*, not merely started. Two failure modes cost a
 * measured seven minutes of invisible work and a scratch tree each:
 *
 *   - The gate spawns its own children (`git`, `bin/qmx`, its worker
 *     processes). Killing the harness alone leaves that tree alive, so a stop
 *     walks `pgrep -P` and signals the whole descendant tree.
 *   - A Ctrl-C on `composer gate:controls` can reach Composer without reaching
 *     this process. Nothing signals us at all, so the supervision loop also
 *     watches for its launcher disappearing and treats that as a stop.
 *
 * The loop drains both pipes with `stream_select` rather than reading stdout to
 * EOF first: the gate writes enough to stderr to fill a pipe buffer, and a
 * blocking read of the other pipe would deadlock instead of finishing.
 *
 * Draining is global, not per child: {@see poll()} selects over every live
 * child at once. That is what lets several controls run side by side, and it is
 * also required by the blocking calls that happen *while* they run — a clone's
 * `cp -Rl` takes ten seconds, and a gate whose 64K pipe buffer fills makes no
 * progress until somebody reads it.
 */
final class Shell
{
    /** @var array<int, Child> pid => unsettled child */
    private static array $children = [];

    private static ?string $stopReason = null;

    private static ?int $launcher = null;

    private static ?Closure $onStop = null;

    /**
     * Arms interruption handling: `$onStop` decides what an interrupted run
     * prints, once the children are dead. `$watchLauncher` off means a run
     * deliberately outliving its launcher, which is otherwise a stop.
     */
    public static function superviseFor(bool $watchLauncher, Closure $onStop): void
    {
        self::$onStop = $onStop;
        self::$launcher = $watchLauncher && \function_exists('posix_getppid') ? posix_getppid() : null;
    }

    public static function requestStop(string $reason): void
    {
        self::$stopReason ??= $reason;
    }

    /**
     * @param list<string> $command
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public static function run(array $command, string $workingDirectory): array
    {
        $child = self::start($command, $workingDirectory);

        while (!$child->settled()) {
            self::poll();
        }

        return $child->result();
    }

    /**
     * Starts a child and returns it unsupervised; the caller keeps it and calls
     * {@see poll()} until it is settled.
     *
     * The environment is stated rather than inherited, so a child cannot pick
     * up whatever the developer's shell happens to carry.
     *
     * @param list<string> $command
     * @param array<string, string> $environment merged over that set, replacing
     */
    public static function start(array $command, string $workingDirectory, array $environment = []): Child
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $handle = proc_open($command, $descriptors, $pipes, $workingDirectory, [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'LC_ALL' => 'C',
            'TZ' => 'UTC',
            'COLUMNS' => '120',
            'NO_COLOR' => '1',
            ...$environment,
        ]);

        if (!\is_resource($handle)) {
            throw new RuntimeException(\sprintf('Cannot start %s.', implode(' ', $command)));
        }

        $pid = proc_get_status($handle)['pid'];
        $child = new Child($handle, $pid, $pipes);
        self::$children[$pid] = $child;

        return $child;
    }

    /**
     * Reads whatever every live child has produced, once, and reaps the settled
     * ones. This is also where a requested stop is acted on, so a caller that
     * polls is interruptible whether its own child is talking or not.
     */
    public static function poll(int $timeoutMicroseconds = 200_000): void
    {
        $read = [];

        foreach (self::$children as $child) {
            foreach ($child->openStreams() as $stream) {
                $read[] = $stream;
            }
        }

        if ($read === []) {
            usleep($timeoutMicroseconds);
        } else {
            $write = $except = [];
            @stream_select($read, $write, $except, 0, $timeoutMicroseconds);
        }

        foreach (self::$children as $pid => $child) {
            $child->drain();

            if ($child->settled()) {
                $child->reap();
                unset(self::$children[$pid]);
            }
        }

        self::stopIfRequested();
    }

    /**
     * The one place a stop is acted on. Signal handlers only raise the flag;
     * the decision is taken here, synchronously, between pipe reads and at
     * control boundaries — so the children are dead before the process leaves.
     */
    public static function stopIfRequested(): void
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if (self::$stopReason === null && self::launcherGone()) {
            self::requestStop('the process that launched the harness is gone');
        }

        if (self::$stopReason === null) {
            return;
        }

        $reason = self::$stopReason;
        self::terminateAll();

        if (self::$onStop !== null) {
            (self::$onStop)($reason);
        }

        exit(130);
    }

    private static function launcherGone(): bool
    {
        return self::$launcher !== null
            && \function_exists('posix_getppid')
            && posix_getppid() !== self::$launcher;
    }

    /**
     * Kills every live child *and its descendants*: the gate's own `git` and
     * `bin/qmx` processes outlive their parent otherwise, which is exactly how
     * an interrupted run kept working invisibly for seven minutes.
     */
    public static function terminateAll(): void
    {
        if (!\function_exists('posix_kill')) {
            self::$children = [];

            return;
        }

        // Every tree is collected and signalled as one set: with a pool there
        // are several of them, and a per-tree grace period would let the last
        // child keep working for as long as the earlier ones took to die.
        $trees = [];

        foreach (array_keys(self::$children) as $pid) {
            $trees = [...$trees, $pid, ...self::descendants($pid)];
        }

        foreach ($trees as $target) {
            @posix_kill($target, \defined('SIGTERM') ? \SIGTERM : 15);
        }

        usleep(300_000);

        foreach ($trees as $target) {
            @posix_kill($target, \defined('SIGKILL') ? \SIGKILL : 9);
        }

        self::$children = [];
    }

    /** @return list<int> */
    private static function descendants(int $pid): array
    {
        $output = @shell_exec('pgrep -P ' . escapeshellarg((string) $pid) . ' 2>/dev/null');
        $pids = [];

        // Tolerant where the rest of the tool throws: this runs from the
        // shutdown path, where an exception would replace the failure being
        // reported with one about killing children.
        $candidates = preg_split('~\s+~', (string) $output, -1, \PREG_SPLIT_NO_EMPTY);

        foreach ($candidates === false ? [] : $candidates as $candidate) {
            $child = (int) $candidate;

            if ($child > 1) {
                $pids[] = $child;
                $pids = [...$pids, ...self::descendants($child)];
            }
        }

        return $pids;
    }

    /** @param list<string> $command */
    public static function mustRun(array $command, string $workingDirectory): string
    {
        $result = self::run($command, $workingDirectory);

        if ($result['exit'] !== 0) {
            throw new RuntimeException(\sprintf(
                "%s failed (exit %d):\n%s",
                implode(' ', $command),
                $result['exit'],
                $result['stderr'],
            ));
        }

        return $result['stdout'];
    }

    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Cannot read %s.', $path));
        }

        return $contents;
    }

    /**
     * Writes by replacing the inode, never through it.
     *
     * A scratch tree is a hardlink clone, so `file_put_contents` on one of its
     * files writes into the *original repository file* — the footgun this whole
     * harness has to avoid. Unlinking first breaks the link, and the fresh file
     * belongs to the scratch tree alone.
     */
    public static function replace(string $path, string $contents): void
    {
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException(\sprintf('Cannot unlink %s.', $path));
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException(\sprintf('Cannot write %s.', $path));
        }
    }

    public static function removeRecursively(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            throw new RuntimeException(\sprintf('Cannot list %s while removing it.', $path));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeRecursively($path . '/' . $entry);
        }

        @rmdir($path);
    }

    public static function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        if (!@mkdir($path, 0o700, true)) {
            throw new RuntimeException(\sprintf('Cannot create temporary directory %s.', $path));
        }

        return $path;
    }
}

/**
 * One started process, drained by {@see Shell::poll()} rather than by a loop of
 * its own.
 *
 * It exists so several controls can be in flight at once: a supervision loop
 * that owns one child cannot read another's pipes, and an unread pipe stops the
 * child that fills it. Liveness is therefore recorded per child — `outputAge()`
 * on the child that has gone quiet, not on a stream where fourteen gates'
 * output would be indistinguishable.
 */
final class Child
{
    /** @var array<int, resource> */
    private array $open;

    /** @var array<int, string> */
    private array $buffers = [1 => '', 2 => ''];

    private ?int $exit = null;

    private int $bytes = 0;

    private float $startedAt;

    private float $lastOutputAt;

    /**
     * @param resource $handle
     * @param array<int, resource> $pipes
     */
    public function __construct(
        private $handle,
        public readonly int $pid,
        array $pipes,
    ) {
        $this->open = [1 => $pipes[1], 2 => $pipes[2]];
        $this->startedAt = microtime(true);
        $this->lastOutputAt = $this->startedAt;

        foreach ($this->open as $stream) {
            stream_set_blocking($stream, false);
        }
    }

    /** @return list<resource> */
    public function openStreams(): array
    {
        return array_values($this->open);
    }

    /** Reads whatever is already available and refreshes the exit status. */
    public function drain(): void
    {
        foreach ($this->open as $key => $stream) {
            $chunk = fread($stream, 65_536);

            if (\is_string($chunk) && $chunk !== '') {
                $this->buffers[$key] .= $chunk;
                $this->bytes += \strlen($chunk);
                $this->lastOutputAt = microtime(true);
            }

            if (feof($stream)) {
                fclose($stream);
                unset($this->open[$key]);
            }
        }

        if ($this->exit === null && \is_resource($this->handle)) {
            $status = proc_get_status($this->handle);

            if ($status['running'] === false) {
                $this->exit = $status['exitcode'];
            }
        }
    }

    /** Both pipes at EOF and the exit code known: nothing more will arrive. */
    public function settled(): bool
    {
        return $this->open === [] && $this->exit !== null;
    }

    /**
     * Closes the process handle. The exit code was taken from
     * `proc_get_status()` before this, because `proc_close()` on an already
     * reaped process reports -1.
     */
    public function reap(): void
    {
        if (\is_resource($this->handle)) {
            proc_close($this->handle);
        }
    }

    /** @return array{stdout: string, stderr: string, exit: int} */
    public function result(): array
    {
        if ($this->exit === null) {
            throw new RuntimeException(\sprintf('Process %d has not exited yet.', $this->pid));
        }

        return ['stdout' => $this->buffers[1], 'stderr' => $this->buffers[2], 'exit' => $this->exit];
    }

    public function age(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function outputAge(): float
    {
        return microtime(true) - $this->lastOutputAt;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }
}
