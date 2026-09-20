<?php

declare(strict_types=1);

namespace Qualimetrix\Subprocess\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__) . '/ChildProcess.php';

final class ChildProcessDrainTest extends TestCase
{
    /**
     * The deadlock property: neither stream is left unread while the other
     * blocks. Measured against three mutants of the drain loop — sequential
     * stdout-first, sequential stderr-first, and `stream_select` kept but
     * blocking left on the descriptors — each of which hangs on this child
     * and only on a child that floods *both* streams. A child flooding one
     * stream leaves the other fully readable and bites none of them.
     *
     * The child writes its **stderr** flood first and its **stdout** flood
     * second, reversed from the concatenation order the assertions expect.
     * `run()` returns stdout and stderr in their own slots regardless of
     * which descriptor's bytes arrived first, so that distinction only shows
     * up when arrival order and expectation order disagree: a mutant that
     * appends both streams onto one slot produced a byte-identical result by
     * coincidence under the un-reversed child order, and passed every
     * assertion here.
     *
     * Each stream carries its own 256-byte cyclic payload — ascending on
     * stdout, descending on stderr — not one repeated byte, and not the same
     * cyclic pattern on both. Measured, both fixes: a homogeneous payload
     * makes reordering *within* one stream unobservable by construction, and
     * a shared cyclic pattern made a slot **swap** pass by coincidence
     * wherever the two floods' lengths overlap, since a prefix of one
     * repeating pattern is byte-identical to a fresh run of the same one.
     *
     * Two known, accepted blind spots. Intra-stream chunk reordering: while a
     * read returns whole multiples of the 256-byte block, a swapped chunk
     * never lands out of phase with the cyclic pattern and stays invisible,
     * and closing that would need a payload encoding absolute position, which
     * this test does not attempt. And a `stream_select` busy-spin, which no
     * byte-content assertion can see at all.
     *
     * A deadlocked `run()` would hang this PHPUnit run rather than fail it —
     * the exact failure mode under test. The call therefore happens in an
     * external harness process whose supervision is `proc_get_status()`
     * polling against a wall clock and never a blocking pipe read, so a
     * regression reddens this test instead of hanging the suite.
     */
    #[Test]
    public function itDrainsAChildThatFloodsBothStreamsWithoutDeadlocking(): void
    {
        // Both well past the 64 KB default OS pipe buffer on macOS and Linux,
        // and deliberately unequal so neither assertion below could pass by
        // coincidence on a same-size flood. Both are multiples of 256.
        $stdoutFloodBytes = 1_048_576;
        $stderrFloodBytes = 2_097_152;
        $outputFile = tempnam(sys_get_temp_dir(), 'qmx-drain-harness-stdout-');
        $errorFile = tempnam(sys_get_temp_dir(), 'qmx-drain-harness-stderr-');
        $harnessPath = tempnam(sys_get_temp_dir(), 'qmx-drain-harness-');
        self::assertIsString($outputFile);
        self::assertIsString($errorFile);
        self::assertIsString($harnessPath);

        try {
            $ascendingBlockExpr = 'implode("", array_map("chr", range(0, 255)))';
            $descendingBlockExpr = 'implode("", array_map("chr", range(255, 0, -1)))';
            $floodCommand = [
                \PHP_BINARY,
                '-r',
                \sprintf(
                    'fwrite(STDERR, str_repeat(%s, %d)); fwrite(STDOUT, str_repeat(%s, %d));',
                    $descendingBlockExpr,
                    intdiv($stderrFloodBytes, 256),
                    $ascendingBlockExpr,
                    intdiv($stdoutFloodBytes, 256),
                ),
            ];

            $result = $this->runHarness(
                $harnessPath,
                $outputFile,
                $errorFile,
                '$result = \Qualimetrix\Subprocess\ChildProcess::run(__COMMAND__);'
                . 'fwrite(STDOUT, $result["exitCode"] . "\n" . $result["stdout"] . $result["stderr"]);',
                $floodCommand,
                '',
            );
            [$timedOut, $harnessExitCode, $harnessOutput, $harnessStderr] = $result;

            self::assertFalse(
                $timedOut,
                'ChildProcess::run() did not finish within the deadline. Historically this meant a stream left '
                . 'unread while the child blocked writing the other past the OS pipe buffer, but the timeout only '
                . 'observes "did not finish" -- treat that as the leading hypothesis, not an established cause. '
                . 'Harness stderr: ' . $harnessStderr,
            );
            self::assertSame(0, $harnessExitCode, 'harness process exit code; stderr: ' . $harnessStderr);

            $parts = explode("\n", $harnessOutput, 2);
            self::assertCount(2, $parts, 'harness output missing the exit-code line; stderr: ' . $harnessStderr);
            [$childExitLine, $childOutput] = $parts;
            self::assertSame('0', $childExitLine, 'flood child exit code; harness stderr: ' . $harnessStderr);
            self::assertSame(
                $stdoutFloodBytes + $stderrFloodBytes,
                \strlen($childOutput),
                'combined drained byte count',
            );
            $ascendingBlock = implode('', array_map('chr', range(0, 255)));
            $descendingBlock = implode('', array_map('chr', range(255, 0, -1)));
            $this->assertStreamBytes(
                str_repeat($ascendingBlock, intdiv($stdoutFloodBytes, 256)),
                substr($childOutput, 0, $stdoutFloodBytes),
                'stdout slot',
            );
            $this->assertStreamBytes(
                str_repeat($descendingBlock, intdiv($stderrFloodBytes, 256)),
                substr($childOutput, $stdoutFloodBytes),
                'stderr slot',
            );
        } finally {
            @unlink($harnessPath);
            @unlink($outputFile);
            @unlink($errorFile);
        }
    }

    /**
     * Distinct from the deadlock case above: this shape cannot hang. Once one
     * descriptor closes, only one stream remains, and any drain — the correct
     * one or the historical buggy one — finishes reading it and returns, so
     * it needs no deadline supervision. What it targets is a different
     * mutant: a drain that returns as soon as *either* stream reaches EOF,
     * discarding whatever the other still had in flight. The deadlock test
     * structurally cannot see that mutant, because both its streams close
     * simultaneously at child exit, after everything has already been read
     * from both.
     *
     * The stderr length assertion comes before the exit-code one on purpose,
     * measured against exactly this mutant: abandoning an unread pipe makes
     * `proc_close()` close it out from under the still-writing child, whose
     * `fwrite()` then fails and which exits 255 — a real but incidental
     * symptom rather than the data loss itself. With the exit-code check
     * first, PHPUnit stops there and never prints the message explaining what
     * was actually lost.
     */
    #[Test]
    public function itDrainsAChildThatClosesOneDescriptorWhileTheOtherKeepsWriting(): void
    {
        // 8x the 64 KB default OS pipe buffer: guarantees the child cannot
        // have finished writing its tail before stdout closes and stderr's
        // buffer fills at least once. Not a guarantee about read-call counts,
        // only about how much is still in flight when a truncating drain
        // would wrongly stop.
        $tailBytes = 524_288;
        $result = ChildProcess::run([
            \PHP_BINARY,
            '-r',
            \sprintf('fclose(STDOUT); fwrite(STDERR, str_repeat("E", %d));', $tailBytes),
        ]);

        // A length check rather than a content comparison: a swapped or merged
        // drain would otherwise put the whole stderr flood into stdout and
        // dump it in full on failure.
        self::assertSame(
            0,
            \strlen($result['stdout']),
            'stdout must stay empty -- closed before anything could be written to it (got '
            . \strlen($result['stdout']) . ' bytes instead)',
        );
        self::assertSame(
            $tailBytes,
            \strlen($result['stderr']),
            'stderr tail must not be lost after stdout closes early — a drain that returns on the '
            . 'first EOF loses whatever the other stream still had in flight',
        );
        self::assertSame(
            0,
            $result['exitCode'],
            'flood child exit code -- reached only once the lengths above already matched, so a '
            . 'non-zero code here is a real, independent problem rather than the abandoned-pipe '
            . 'symptom the length checks above would otherwise have masked',
        );
    }

    /**
     * The stdin mirror of the deadlock property. A parent that writes stdin to
     * completion before draining blocks in `fwrite()` exactly as a sequential
     * reader blocks in `fread()`: measured, a parent pushing 1 MB at a child
     * that never reads stdin was still blocked at 5 s and had to be SIGKILLed.
     *
     * The child's order is what makes that mutant bite and is not
     * interchangeable: it floods stdout *first* and only then reads stdin. A
     * child reading stdin first would drain the parent's writes as fast as
     * they arrive, and a write-stdin-first parent would never block. Under
     * this order both buffers fill at once, so only a parent servicing reads
     * and writes from the same loop gets through.
     *
     * What comes back from the child is the stdin it received, byte for byte,
     * and not its length: a drain that reordered chunks or spliced in bytes
     * from the wrong buffer while preserving the total would pass a length
     * check untouched. The payload is eight-byte blocks each naming their own
     * index, so any swap between blocks is visible — the repeated filler byte
     * this case used to carry makes reordering unobservable by construction,
     * and a cyclic pattern, as the deadlock case above records, hides every
     * swap that happens to land in phase with it. The comparison is
     * `assertStreamBytes()`, for the reason given there: a length and the
     * first differing offset instead of a quarter of a megabyte printed twice.
     *
     * Same external harness and wall-clock supervision as the deadlock test,
     * and for the same reason.
     */
    #[Test]
    public function itFeedsStdinWhileDrainingAChildThatFloodsStdoutFirst(): void
    {
        $stdoutFloodBytes = 1_048_576;
        // Four times the 64 KB pipe buffer, so no parent can place it in one
        // write however the child behaves, and every block says where it
        // belongs.
        $stdinPayload = implode('', array_map(
            static fn(int $block): string => \sprintf('%07d|', $block),
            range(0, 32_767),
        ));
        $outputFile = tempnam(sys_get_temp_dir(), 'qmx-stdin-harness-stdout-');
        $errorFile = tempnam(sys_get_temp_dir(), 'qmx-stdin-harness-stderr-');
        $harnessPath = tempnam(sys_get_temp_dir(), 'qmx-stdin-harness-');
        self::assertIsString($outputFile);
        self::assertIsString($errorFile);
        self::assertIsString($harnessPath);

        try {
            $command = [
                \PHP_BINARY,
                '-r',
                \sprintf(
                    'fwrite(STDOUT, str_repeat("O", %d)); fwrite(STDERR, stream_get_contents(STDIN));',
                    $stdoutFloodBytes,
                ),
            ];

            [$timedOut, $harnessExitCode, $harnessOutput, $harnessStderr] = $this->runHarness(
                $harnessPath,
                $outputFile,
                $errorFile,
                '$result = \Qualimetrix\Subprocess\ChildProcess::run(__COMMAND__, null, __STDIN__);'
                . 'fwrite(STDOUT, $result["exitCode"] . "\n" . strlen($result["stdout"]) . "\n" . $result["stderr"]);',
                $command,
                $stdinPayload,
            );

            self::assertFalse(
                $timedOut,
                'ChildProcess::run() did not finish within the deadline while feeding stdin. The leading '
                . 'hypothesis is a parent writing stdin to completion before draining stdout, which blocks '
                . 'once both pipe buffers are full. Harness stderr: ' . $harnessStderr,
            );
            self::assertSame(0, $harnessExitCode, 'harness process exit code; stderr: ' . $harnessStderr);

            $parts = explode("\n", $harnessOutput, 3);
            self::assertCount(3, $parts, 'harness output is not three lines; stderr: ' . $harnessStderr);
            self::assertSame('0', $parts[0], 'child exit code; harness stderr: ' . $harnessStderr);
            self::assertSame((string) $stdoutFloodBytes, $parts[1], 'drained stdout byte count');
            $this->assertStreamBytes(
                $stdinPayload,
                $parts[2],
                'the child must receive every stdin byte the parent was given, in order',
            );
        } finally {
            @unlink($harnessPath);
            @unlink($outputFile);
            @unlink($errorFile);
        }
    }

    /**
     * The other half of the stdin contract, and the shape almost every caller
     * in this tree actually uses: no stdin at all. A child that reads STDIN to
     * EOF — `stream_get_contents(STDIN)`, `file_get_contents('php://stdin')`,
     * a `git` subcommand waiting on a list — only ever returns if the parent
     * closes its end of that pipe. Nothing else in this file covers it, and
     * not because of the payloads those cases pass — two of them pass none
     * either. It is what their children do: none of them ever reads stdin, so
     * they exit whether or not that descriptor was closed, and the one case
     * that does feed stdin has its descriptor closed by the ordinary write
     * path when the payload runs out, which is a different line of code. The
     * close the empty-payload branch has to perform is reached by no other
     * case here. Measured: deleting that one line leaves every other case in
     * this file green and hangs this one.
     *
     * The child's stdout is what proves EOF was actually reached rather than
     * the read merely being skipped: it reports the byte count it read, which
     * must be 0 — a child that never got EOF cannot report anything at all.
     *
     * Same external harness and wall-clock supervision as the two cases above:
     * this case can hang, and a hung PHPUnit run says only that something
     * stopped, where a red says what.
     */
    #[Test]
    public function itClosesStdinAtOnceWhenGivenNoneSoAChildReadingItReachesEof(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'qmx-stdin-eof-harness-stdout-');
        $errorFile = tempnam(sys_get_temp_dir(), 'qmx-stdin-eof-harness-stderr-');
        $harnessPath = tempnam(sys_get_temp_dir(), 'qmx-stdin-eof-harness-');
        self::assertIsString($outputFile);
        self::assertIsString($errorFile);
        self::assertIsString($harnessPath);

        try {
            [$timedOut, $harnessExitCode, $harnessOutput, $harnessStderr] = $this->runHarness(
                $harnessPath,
                $outputFile,
                $errorFile,
                '$result = \Qualimetrix\Subprocess\ChildProcess::run(__COMMAND__, null, __STDIN__);'
                . 'fwrite(STDOUT, $result["exitCode"] . "\n" . $result["stdout"]);',
                [\PHP_BINARY, '-r', 'fwrite(STDOUT, (string) strlen(stream_get_contents(STDIN)));'],
                '',
            );

            self::assertFalse(
                $timedOut,
                'ChildProcess::run() did not finish within the deadline with an empty stdin. The leading '
                . 'hypothesis is a stdin descriptor left open although there was nothing to write to it, '
                . 'which leaves a child reading STDIN to EOF waiting forever. Harness stderr: ' . $harnessStderr,
            );
            self::assertSame(0, $harnessExitCode, 'harness process exit code; stderr: ' . $harnessStderr);

            $parts = explode("\n", $harnessOutput, 2);
            self::assertCount(2, $parts, 'harness output is not two lines; stderr: ' . $harnessStderr);
            self::assertSame('0', $parts[0], 'child exit code; harness stderr: ' . $harnessStderr);
            self::assertSame(
                '0',
                $parts[1],
                'the child must read end-of-file from stdin immediately and report zero bytes',
            );
        } finally {
            @unlink($harnessPath);
            @unlink($outputFile);
            @unlink($errorFile);
        }
    }

    /**
     * A child that never reads stdin and exits makes the parent's `fwrite()`
     * fail with EPIPE. That is a normal path, not a failure: the runner stops
     * writing, closes the descriptor and keeps draining. Runs in-process
     * deliberately — `failOnNotice` is on, so an unsuppressed EPIPE notice
     * reddens this test, which is the symptom this case exists to pin. It
     * cannot hang: the child writes nothing and the payload below is bounded.
     *
     * That payload's size is the whole case, and it is not "one buffer's
     * worth". It has to exceed the OS pipe buffer — measured at 65536 bytes
     * here, the documented default on Linux too — because EPIPE is reachable
     * only while something is still pending once that buffer is full.
     * Measured, both halves: at two buffers the EPIPE branch is taken exactly
     * once, and at exactly one buffer the first write swallows the payload
     * whole, `$pending` empties, the descriptor closes down the ordinary path
     * and the branch is never entered — leaving this case green while testing
     * nothing at all. Rounding the number down deletes the case without
     * reddening anything.
     *
     * The bound is the pipe buffer and not the module's `WRITE_CHUNK_BYTES`,
     * which is the same 65536 today. That coincidence is exactly why the
     * relationship is written here instead of being derived from the
     * constant: a smaller chunk size still needs this payload, and deriving
     * it from the constant would shrink the payload below the buffer and make
     * the case vacuous.
     */
    #[Test]
    public function itTreatsAChildThatNeverReadsStdinAsASuccess(): void
    {
        $pipeBufferBytes = 65_536;
        $result = ChildProcess::run(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, "done");'],
            null,
            str_repeat('I', 2 * $pipeBufferBytes),
        );

        self::assertSame('done', $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertSame(0, $result['exitCode']);
    }

    /**
     * The child's exit code reaches the caller. Nothing else in this file can
     * see that: every other child here exits 0 and every other case asserts
     * 0, so a runner reporting a literal 0 in place of `proc_close()`'s return
     * value passes the whole file. Measured: that mutant reddens this case
     * and only this case.
     *
     * Two different non-zero codes rather than one, because a single value
     * cannot separate "the code is propagated" from "this constant is what
     * gets returned". Adding 0 as a third would cost nothing and prove
     * nothing the other two do not.
     *
     * The stdout assertion rides along so that a run which produced the right
     * number by dying before it did its work cannot pass as a run that
     * produced it properly. In-process: the child writes three bytes and
     * exits, so no buffer fills and nothing here can block.
     */
    #[Test]
    public function itReportsTheChildsOwnExitCode(): void
    {
        foreach ([3, 42] as $exitCode) {
            $result = ChildProcess::run([
                \PHP_BINARY,
                '-r',
                \sprintf('fwrite(STDOUT, "run"); exit(%d);', $exitCode),
            ]);

            self::assertSame($exitCode, $result['exitCode'], 'reported exit code of a child exiting ' . $exitCode);
            self::assertSame('run', $result['stdout'], 'stdout of a child exiting ' . $exitCode);
            self::assertSame('', $result['stderr'], 'stderr of a child exiting ' . $exitCode);
        }
    }

    /**
     * Writes a harness script that loads the module by path — no
     * `vendor/autoload.php`, the way the vendor-less callers load it — runs
     * `$body` in it, and supervises it by wall clock.
     *
     * @param list<string> $command
     *
     * @return array{bool, int, string, string} timed out, exit code, stdout, stderr
     */
    private function runHarness(
        string $harnessPath,
        string $outputFile,
        string $errorFile,
        string $body,
        array $command,
        string $stdin,
    ): array {
        $source = "<?php\nrequire_once " . var_export($this->modulePath(), true) . ";\n"
            . str_replace(
                ['__COMMAND__', '__STDIN__'],
                [var_export($command, true), var_export($stdin, true)],
                $body,
            ) . "\n";
        self::assertNotFalse(file_put_contents($harnessPath, $source));

        [$exitCode, $timedOut] = $this->runWithDeadline([\PHP_BINARY, $harnessPath], 5.0, $outputFile, $errorFile);
        $stdout = file_get_contents($outputFile);
        $stderr = file_get_contents($errorFile);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [$timedOut, $exitCode, $stdout, $stderr];
    }

    private function modulePath(): string
    {
        $path = realpath(\dirname(__DIR__) . '/ChildProcess.php');
        self::assertIsString($path);

        return $path;
    }

    /**
     * Bounds `$command` by wall clock without ever performing a blocking pipe
     * read on it: only `proc_get_status()` polling and, past the deadline,
     * `proc_terminate()`. A supervisor that itself read a pipe from `$command`
     * could deadlock exactly the way the code under test might, which is why
     * `$command`'s own stdout and stderr go straight to files — `file`
     * descriptors, not `pipe` ones — instead of being read by this process.
     *
     * @param list<string> $command
     *
     * @return array{int, bool} the process exit code (meaningless when the
     *                          deadline was hit) and whether the deadline was hit
     */
    private function runWithDeadline(array $command, float $seconds, string $stdoutFile, string $stderrFile): array
    {
        $process = proc_open($command, [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes);
        self::assertIsResource($process);

        $deadline = microtime(true) + $seconds;
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            self::assertIsArray($status);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, \defined('SIGKILL') ? \SIGKILL : 9);
                break;
            }
            usleep(20_000);
        }

        return [proc_close($process), $timedOut];
    }

    /**
     * A cheap-first, short-on-failure comparison for the multi-megabyte
     * payloads above. `self::assertSame()` on two ~1-2 MB strings would, on a
     * mismatch, print both operands in full — roughly 2 MB across the two
     * assertions this replaces, for a defect whose useful description is a
     * length and a byte offset.
     */
    private function assertStreamBytes(string $expected, string $actual, string $label): void
    {
        self::assertSame(\strlen($expected), \strlen($actual), $label . ': byte count');
        if ($expected === $actual) {
            return;
        }

        $offset = 0;
        $length = \strlen($expected);
        while ($offset < $length && $expected[$offset] === $actual[$offset]) {
            ++$offset;
        }
        self::fail(\sprintf(
            '%s: content differs at byte offset %d (expected 0x%02X, got 0x%02X)',
            $label,
            $offset,
            \ord($expected[$offset]),
            \ord($actual[$offset]),
        ));
    }
}
