<?php

declare(strict_types=1);

namespace Qualimetrix\Subprocess;

use RuntimeException;
use Throwable;

/**
 * The repository's one deadlock-free way to run a child process and capture
 * everything it writes.
 *
 * A sequential read — stdout to EOF, then stderr — deadlocks once the child
 * writes more than the OS pipe buffer (64 KB on both macOS and Linux by
 * default) to whichever stream is read second: the child blocks mid-write on
 * that stream, so it never exits or closes the first stream, and the parent's
 * blocking read of the first stream never reaches EOF. Both sides then wait
 * forever, which is strictly worse than failing — a red says what broke, a
 * hung CI job says only that something did. Draining every descriptor from a
 * single `stream_select()` loop is the shape that cannot deadlock this way,
 * because no stream is ever left unread while another blocks.
 *
 * The same hazard is mirrored on stdin, and measured rather than reasoned
 * about: a parent pushing 1 MB into the stdin pipe of a child that never reads
 * stdin was still blocked in `fwrite()` at 5 s and had to be SIGKILLed. That
 * is why stdin is fed from inside the same loop instead of being written to
 * completion first.
 *
 * ## One file, one class, no dependencies
 *
 * Five callers of this class run without `vendor/autoload.php` at all —
 * `generate-suppression-snapshot.php`, `generate-modular-architecture.php`,
 * `generate-modular-architecture-test-inventory.php`,
 * `benchmark-regression.php` and `collect-benchmark-data.php` — so anything
 * reachable only through Composer's autoloader cannot be the repository's one
 * safe way. They are listed rather than counted because an earlier version of
 * this paragraph said "both modular-architecture generators" when there are
 * three of them, and named two of the five. That is also why failure is
 * signalled with the built-in `\RuntimeException` rather than a named
 * exception class: PSR-4 would put that class in a second file, and it would
 * then be missing exactly on the error path, where nothing exercises it.
 *
 * ## Loading: both mechanisms, deliberately
 *
 * This namespace is declared in `composer.json`'s `autoload-dev.psr-4` *and*
 * every caller `require_once`s this file by path. Each half closes a hole the
 * other leaves open, measured directly rather than argued:
 *
 * - Without the declaration, the repository's ban on `src/` importing
 *   development code cannot see this namespace at all — that control derives
 *   its list of development namespaces from `autoload-dev.psr-4` alone, so an
 *   undeclared namespace imported from production source produces no refusal,
 *   and PHPStan does not substitute for it because `scripts/` is in its
 *   analysed paths.
 * - Without the `require_once`, the isolated-project negative controls break:
 *   they symlink `vendor/`, so an autoloaded class resolves through that
 *   symlink's `autoload_psr4.php` to *this* tree rather than to the scratch
 *   copy under test, and a deliberately broken scratch copy is never read.
 *
 * Residual cost of keeping both: a second class added beside this one becomes
 * autoloadable by declaration, and would need its own explicit `require_once`
 * to stay isolation-safe. The declaration makes that possible to forget, not
 * automatic.
 *
 * ## Known boundaries
 *
 * Named rather than solved, and each one measured on this tree:
 *
 * - `run()` returns when the *pipes* reach EOF, not when the child exits. A
 *   grandchild inheriting the child's stdout holds that pipe open after the
 *   child is gone, and the parent waits for it:
 *   `['/bin/sh', '-c', '( sleep 6 ) & echo parent-done']` keeps `run()` there
 *   for the full six seconds. No caller in this tree has that shape. Measured
 *   over `src/` — 973 files, above the product's own sequential-fallback
 *   floor, so `--workers=4` there starts workers instead of falling back, and
 *   four `amphp/parallel` processes were confirmed running: stdout reached EOF
 *   in the same 20 ms window the child was reaped, so those workers do not
 *   hold the parent's stdout. A deadline here would be supervision, which is
 *   deliberately a different subject.
 * - `stream_select()` is interrupted by a signal: it returns `false`, which
 *   this class reports as a failure, where an old-style blocking read would
 *   have resumed. Nothing retries on EINTR. No caller installs a signal
 *   handler today, so the case has no live victim — it is written down so the
 *   first caller that does install one knows what changes.
 * - An empty command is not this class's failure to report. `run([])` raises
 *   PHP's own `ValueError` from `proc_open()`, and `run('')` starts a shell
 *   that does nothing and exits 0. Converting the first into the
 *   `RuntimeException` below was rejected rather than overlooked: the
 *   `ValueError` names the argv defect exactly, and folding it in would hide
 *   that behind the same report any other failed command produces.
 */
final class ChildProcess
{
    /**
     * The failure this exception reports, as a prefix of its message. A named
     * exception class per failure is ruled out by the one-file rule above, so
     * a caller that must tell the failures apart matches on these:
     * `str_starts_with($error->getMessage(), ChildProcess::START_FAILURE_PREFIX)`.
     * The prefixes are stable; the remainder of the message names the command
     * or the operating system's own wording and is not.
     */
    public const string START_FAILURE_PREFIX = 'Cannot start command: ';

    /** @see self::START_FAILURE_PREFIX */
    public const string READ_FAILURE_PREFIX = 'Cannot read command output: ';

    /** @see self::START_FAILURE_PREFIX */
    public const string WRITE_FAILURE_PREFIX = 'Cannot write command input: ';

    /**
     * How much of `$stdin` is handed to one `fwrite()`. One pipe buffer: the
     * write is non-blocking and may accept less, so what actually advances is
     * the remainder still to be written, not this number.
     */
    private const int WRITE_CHUNK_BYTES = 65536;

    /**
     * Starts the command, feeds `$stdin`, drains stdout and stderr until both
     * reach EOF, and reaps the child. What it waits for is those two pipes
     * reaching EOF, not the child exiting — the first of the boundaries above
     * is the case where those two stop being the same moment.
     *
     * A non-zero exit code is a result, not a failure: most callers assert on
     * it. Supervision — deadlines, process groups, descendant kills — is a
     * different subject and deliberately not here; a caller that needs a
     * bounded wait keeps its own.
     *
     * @param list<string>|string $command
     * @param array<string,string>|null $environment null inherits the parent's
     *
     * @throws RuntimeException one of three distinct failures, told apart by
     *                          the message prefix and not by the class:
     *                          {@see self::START_FAILURE_PREFIX} the child
     *                          never started, so nothing ran;
     *                          {@see self::READ_FAILURE_PREFIX} it started and
     *                          its output is incomplete;
     *                          {@see self::WRITE_FAILURE_PREFIX} it started and
     *                          received a truncated stdin. The last two leave
     *                          the child reaped but its work half-done, which
     *                          is why they must not read as "could not start".
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public static function run(
        array|string $command,
        ?string $workingDirectory = null,
        string $stdin = '',
        ?array $environment = null,
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, $environment);
        if (!\is_resource($process)) {
            throw new RuntimeException(
                self::START_FAILURE_PREFIX . (\is_array($command) ? implode(' ', $command) : $command),
            );
        }

        try {
            [$stdout, $stderr] = self::drain($pipes[0], $pipes[1], $pipes[2], $stdin);
        } catch (Throwable $failure) {
            // Reap before rethrowing. Without this the child is left
            // unreaped and its descriptors leak, in processes that go on to
            // start hundreds more of them.
            //
            // Closing the pipes first is redundant on PHP 8.5, and kept
            // anyway. Measured, against a child blocked writing 8 MB into an
            // unread stdout: `proc_close()` closes the pipes it opened itself
            // and returns in 4 ms, the child taking EPIPE and exiting 255. The
            // manual documents the opposite ("you should always close any
            // pipes before calling this to avoid a deadlock"), and a hung reap
            // is the very failure this class exists to prevent, so the error
            // path is not rested on a behaviour no version promises.
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);

            throw $failure;
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => proc_close($process),
        ];
    }

    /**
     * The one select loop. Every descriptor the parent holds is serviced by
     * it, so none is left unattended while the child blocks on another.
     *
     * It may leave descriptors open when it throws — reaping and cleaning up
     * after that belong to `run()`, which holds the process handle.
     *
     * @param resource $stdinPipe
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     *
     * @return array{string, string} stdout, stderr
     */
    private static function drain($stdinPipe, $stdoutPipe, $stderrPipe, string $stdin): array
    {
        stream_set_blocking($stdinPipe, false);
        stream_set_blocking($stdoutPipe, false);
        stream_set_blocking($stderrPipe, false);

        $reads = [
            (int) $stdoutPipe => ['stream' => $stdoutPipe, 'index' => 0],
            (int) $stderrPipe => ['stream' => $stderrPipe, 'index' => 1],
        ];
        $output = ['', ''];

        $pending = $stdin;
        $writeStream = $stdinPipe;
        if ($pending === '') {
            fclose($stdinPipe);
            $writeStream = null;
        }

        while ($reads !== [] || $writeStream !== null) {
            $read = array_column($reads, 'stream');
            $write = $writeStream === null ? [] : [$writeStream];
            $except = null;
            if (stream_select($read, $write, $except, null) === false) {
                throw new RuntimeException(self::READ_FAILURE_PREFIX . 'stream_select() failed on the pipes.');
            }

            foreach ($read as $stream) {
                $key = (int) $stream;
                $chunk = stream_get_contents($stream);
                if ($chunk === false) {
                    throw new RuntimeException(self::READ_FAILURE_PREFIX . 'a pipe could not be read.');
                }

                $output[$reads[$key]['index']] .= $chunk;

                if (feof($stream)) {
                    fclose($stream);
                    unset($reads[$key]);
                }
            }

            if ($write === [] || $writeStream === null) {
                continue;
            }

            // A child that exits, or closes stdin without reading it, makes
            // this write fail with EPIPE and emit a notice. That is a normal
            // path, not an error: stop writing, close the descriptor and keep
            // draining the reads. The suppression is what keeps the notice
            // from turning a working call into a PHPUnit failure or stderr
            // noise in a vendor-less script.
            //
            // Only EPIPE, though. `@fwrite()` returning false for any other
            // reason — ENOSPC, EIO, a descriptor pulled out from under us —
            // would otherwise take the same branch and be reported as a
            // success, leaving the caller unable to tell a child that ignored
            // its input from one that silently received a truncated copy of
            // it. The message of the suppressed notice is the only place the
            // stream layer exposes the errno to userland ("Write of N bytes
            // failed with errno=32 Broken pipe"), and `error_clear_last()` is
            // what keeps an older suppressed notice from being read as this
            // write's outcome. EPIPE is errno 32 on both macOS and Linux.
            //
            // A pipe that is merely full is not this branch at all: a
            // non-blocking `fwrite()` returns 0 there and raises nothing
            // (measured), so the loop simply retries after the next select.
            error_clear_last();
            $written = @fwrite($writeStream, substr($pending, 0, self::WRITE_CHUNK_BYTES));
            if ($written === false) {
                $reason = error_get_last()['message'] ?? 'fwrite() failed without raising an error.';
                if (preg_match('/\berrno=32\b/', $reason) !== 1) {
                    throw new RuntimeException(self::WRITE_FAILURE_PREFIX . $reason);
                }

                fclose($writeStream);
                $writeStream = null;

                continue;
            }

            $pending = substr($pending, $written);
            if ($pending === '') {
                fclose($writeStream);
                $writeStream = null;
            }
        }

        return [$output[0], $output[1]];
    }
}
