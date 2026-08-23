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
 */
final class Shell
{
    /** @var array<int, resource> pid => process handle */
    private static array $live = [];

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
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $handle = proc_open($command, $descriptors, $pipes, $workingDirectory, [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'LC_ALL' => 'C',
            'TZ' => 'UTC',
            'COLUMNS' => '120',
            'NO_COLOR' => '1',
        ]);

        if (!\is_resource($handle)) {
            throw new RuntimeException(\sprintf('Cannot start %s.', implode(' ', $command)));
        }

        $pid = (int) proc_get_status($handle)['pid'];
        self::$live[$pid] = $handle;

        try {
            return self::supervise($handle, $pipes);
        } finally {
            unset(self::$live[$pid]);
            proc_close($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array<int, resource> $pipes
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private static function supervise($handle, array $pipes): array
    {
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $buffers = [1 => '', 2 => ''];
        $exit = null;

        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }

        while ($open !== [] || $exit === null) {
            if ($open !== []) {
                $read = array_values($open);
                $write = $except = [];
                @stream_select($read, $write, $except, 0, 200_000);

                foreach ($open as $key => $stream) {
                    $chunk = fread($stream, 65_536);

                    if (\is_string($chunk) && $chunk !== '') {
                        $buffers[$key] .= $chunk;
                    }

                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$key]);
                    }
                }
            } else {
                usleep(100_000);
            }

            self::stopIfRequested();

            if ($exit === null) {
                $status = proc_get_status($handle);

                if ($status['running'] === false) {
                    $exit = (int) $status['exitcode'];
                }
            }
        }

        return ['stdout' => $buffers[1], 'stderr' => $buffers[2], 'exit' => $exit];
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
            self::$live = [];

            return;
        }

        foreach (array_keys(self::$live) as $pid) {
            $tree = [$pid, ...self::descendants($pid)];

            foreach ($tree as $target) {
                @posix_kill($target, \defined('SIGTERM') ? \SIGTERM : 15);
            }

            usleep(300_000);

            foreach ($tree as $target) {
                @posix_kill($target, \defined('SIGKILL') ? \SIGKILL : 9);
            }
        }

        self::$live = [];
    }

    /** @return list<int> */
    private static function descendants(int $pid): array
    {
        $output = @shell_exec('pgrep -P ' . escapeshellarg((string) $pid) . ' 2>/dev/null');
        $pids = [];

        foreach (preg_split('~\s+~', (string) $output, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
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

        foreach (scandir($path) ?: [] as $entry) {
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
