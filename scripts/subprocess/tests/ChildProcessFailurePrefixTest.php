<?php

declare(strict_types=1);

namespace Qualimetrix\Subprocess\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__) . '/ChildProcess.php';

/**
 * The three prefixes are the whole of how a caller tells one failure from
 * another. All three operational failures arrive as the same `RuntimeException`
 * — the argument-shaped `\ValueError` the module deliberately lets through on an
 * empty command is a different subject, named in the module's own docblock — and
 * `run()`'s docblock names
 * `str_starts_with($error->getMessage(), ChildProcess::START_FAILURE_PREFIX)` as
 * the way to dispatch on which failure happened. Callers across the tree report
 * a failure without claiming which of the three it was, so the prefix is the
 * only thing left that says so.
 *
 * Two halves, and they fail for different reasons. The *shape* of the prefixes
 * is what dispatch mechanically depends on: each non-empty, pairwise distinct,
 * none a prefix of another. The *correspondence* is what makes the shape worth
 * anything: that each `throw` reaches for the constant naming the failure that
 * actually happened. Swapping two constants at the throw sites keeps every
 * shape property true and makes every caller report the opposite of what
 * occurred.
 *
 * ## How the correspondence is measured
 *
 * By making the operation each throw site guards fail, and reading back which
 * prefix came out. The module calls `proc_open()`, `stream_set_blocking()`,
 * `stream_select()`, `stream_get_contents()` and `fwrite()` by their unqualified
 * names inside `Qualimetrix\Subprocess`, so a function of that name declared in
 * the same namespace is what those call sites resolve to — PHP looks in the
 * current namespace before falling back to the global one. Each case runs in its
 * own process, because that declaration is process-wide and cannot be undone.
 *
 * The unqualified spelling is not a lucky accident, and this is the one style
 * fact the mechanism rests on. `native_function_invocation` here is
 * `['include' => ['@compiler_optimized'], 'scope' => 'namespaced',
 * 'strict' => true]`. Measured against the fixer's own set: `is_array` and
 * `is_resource` are in it, which is why those are the two names the module
 * writes with a leading backslash, and none of the five names above is — so
 * `strict` would *remove* a backslash written on them. The spelling the
 * injection needs is the spelling the style rule enforces. Measured rather than
 * argued: widening that rule to `@all` on a copy qualifies all five names and
 * turns all of these cases red.
 *
 * ## Three ways a case could pass without measuring anything, and what stops each
 *
 * Every one of them was a live defect in an earlier revision of this file, so
 * each is written down beside the thing that closes it.
 *
 * - **The shadow never reached the call.** Then the real function runs, `run()`
 *   succeeds, and the harness reports `RETURNED`: a red naming the mechanism, on
 *   every platform where the mechanism is broken. That is the inverse of
 *   `10978986`, which was green on the machine it was written on and red on both
 *   CI runners. It is also why the failures are injected rather than provoked:
 *   the start failure needs a child that cannot be created, and whether an
 *   unusable working directory surfaces in the *parent* depends on whether the
 *   build changes directory before or after the fork; the read failure needs a
 *   pipe that refuses to go non-blocking, a failing `stream_select()` or an
 *   unreadable pipe, each beside a live child; the write failure needs a stdin
 *   descriptor that dies for a reason other than the far end closing, which is a
 *   normal path the module handles without raising. Nothing portable produces
 *   any of the three.
 * - **Some other failure produced the same prefix.** The three read sites share
 *   `READ_FAILURE_PREFIX`, and the module reaches them in a fixed order —
 *   `stream_set_blocking()`, then `stream_select()`, then
 *   `stream_get_contents()` — so a real select failure — `EINTR`, which the
 *   module names as a boundary it does not survive — would satisfy a prefix
 *   assertion in a case that injects one of the *other* read sites. Each shadow
 *   therefore records that it ran, and every case asserts that its own
 *   operation, and only its own, is what failed. A prefix alone is not accepted
 *   as evidence that the injected operation is the one that failed.
 * - **The module stopped throwing and the run hung instead of reddening.** A
 *   shadow that failed on every call left `drain()` spinning when its throw site
 *   was removed — exactly the defect these cases exist to catch, turned into a
 *   hang that eats the aggregate's whole deadline and names no case. Each shadow
 *   therefore fails *once* and then delegates to the real function, so the loop
 *   makes progress whether or not the module throws. A missing throw now reports
 *   `RETURNED` like any other non-failure. That is a stronger guarantee than a
 *   deadline: the hang is impossible rather than bounded, and nothing here
 *   depends on timing or on the correctness of the code under measurement.
 *
 * ## The axes, and which ones are pinned
 *
 * A prefix chosen from the *state of the drain loop* rather than from the throw
 * site would be a different defect with the same symptom, and it is reachable by
 * an ordinary-looking refactor: collapse the throws into one helper that
 * decides the failure kind from `$writeStream` or from which stream failed.
 * Measured, on a copy: with every case carrying an empty stdin except the write
 * one, and stdout the only stream ever failing, two such mutants passed every
 * case while telling a caller with stdin that a failed select was a failed
 * write.
 *
 * So two axes are pinned by running the same shadow against both values. Stdin:
 * the spawn and select failures are each measured with and without a payload.
 * Which stream failed: the read shadow fails on the stream that actually carried
 * bytes, and a child writing only to stdout and one writing only to stderr make
 * that stdout in one case and stderr in another.
 *
 * The non-blocking switch has neither axis, and a third one of its own. It runs
 * once per serviced descriptor, before the loop, so no iteration of the loop is
 * involved; and a case cannot say which descriptor its call carried, because the
 * module hands the shadow no name. What can be lifted is a guard, and there are
 * three to lift. Measured, when one shadow served both cases and could only fail
 * on the first call: a mutant that kept the check on stdin and dropped it from
 * the two read pipes passed every case — while the hazard the whole site exists
 * for lives on exactly those two. The shadow therefore counts its calls and
 * there is a case per call, so dropping any single guard moves a case off its
 * own operation and reddens it. The third call is also where this site meets a
 * payload: stdin is serviced, and so guarded, only when there is one.
 *
 * One axis is left unpinned and named rather than implied: in the loop, the
 * failure always falls on its first iteration. Pinning it would need a shadow
 * that counts iterations, and no mutant has been shown to exploit it.
 *
 * ## What is still not held
 *
 * - That an operating-system condition *reaches* these return values in the
 *   parent on a given platform. What is injected is the value the module
 *   branches on, so what is held is "when the spawn reports failure, the caller
 *   is told the child never started" — not that an unusable working directory
 *   makes the spawn report failure here.
 * - The wording of the constants. Exchanging the three string values passes every
 *   case here, because a case compares a message against the same constant the
 *   module built it from. Nothing in the tree reads the human-readable text, and
 *   the property dispatch needs — that the three stay tellable apart — is held
 *   above.
 * - Which of the three read sites failed, *from a caller's seat*. The contract
 *   has three kinds and not five; all three sites are measured separately here,
 *   but a caller cannot tell a pipe that would not go non-blocking from a failed
 *   select or from an unreadable pipe.
 * - Which descriptor a non-blocking-switch case made refuse. The cases select a
 *   call number, and nothing carries the descriptor back, so reordering the two
 *   read pipes is invisible here. What is held instead is that no guard may go
 *   missing, which is the property the site exists for.
 * - That the module still *declines* to raise on a stdin pipe whose far end
 *   merely closed. A mutant that dropped the `errno=32` guard and always threw
 *   would pass the write case here.
 *   `ChildProcessDrainTest::itTreatsAChildThatNeverReadsStdinAsASuccess()` is
 *   what exercises that branch, with a payload twice the pipe buffer at a child
 *   that never reads stdin; its assertions observe the successful outcome rather
 *   than the errno, so that the branch was entered is an inference from the
 *   buffer size recorded in that test's own docblock, not something either file
 *   asserts.
 *
 * Which builtin each throw site guards is knowledge of the implementation, and
 * the table below is the only place this file holds any. What it asserts is the
 * contract: the failure a caller is told about is the one that happened.
 */
final class ChildProcessFailurePrefixTest extends TestCase
{
    /**
     * Returning `false` is this function's documented way of reporting that it
     * could not start the command, and `$pipes` is left with nothing in it. The
     * name is spelled plainly, so this line carries an entry in
     * `SubprocessReadsAreDrainedConcurrentlyTest::ENTRIES` — not because
     * anything here spawns a child, but because that control refuses the name
     * wherever it appears outside the module, which is what keeps a real
     * undeclared spawn from hiding in a string.
     *
     * Nothing to delegate to on a second call: the module calls this once per
     * run, and a run whose spawn failed has nothing further to spawn.
     */
    private const string SHADOW_SPAWN_FAILS = <<<'PHP'
        function proc_open($command, $descriptors, &$pipes, $cwd = null, $environment = null)
        {
            $GLOBALS['reached'][] = __FUNCTION__;
            $pipes = [];

            return false;
        }
        PHP;

    /**
     * Counts its calls instead of failing on the first, which is what reaches
     * the later guards at all. The module switches the pipes it services in a
     * fixed order, so the call number picks one out: 1 and 2 are the read pipes,
     * 3 is stdin, reached only when there is a payload to deliver.
     * {@see self::failingOnCall()} fills the number in.
     *
     * What a case then holds is that the guard on *that call* is present, not
     * which descriptor the call carried: `$GLOBALS['reached']` records that the
     * shadow ran, and the module hands it no name. Measured: exchanging
     * `$stdoutPipe` and `$stderrPipe` in the serviced list leaves every case
     * green — an equivalent mutant, since the two are read pipes serviced
     * alike.
     *
     * Failing once and delegating afterwards is the shared rule of this file,
     * and here it is not what rules the hang out: measured, a variant refusing
     * on *every* call also returns rather than hanging, because these children
     * write one byte and exit, so even a read to EOF on a blocking descriptor
     * ends. What is held is the outcome — with the throw site removed each of
     * these cases reports `RETURNED` in about a second — not a mechanism that
     * produces it.
     */
    private const string SHADOW_SET_BLOCKING_FAILS = <<<'PHP'
        function stream_set_blocking($stream, $enable)
        {
            static $calls = 0;
            if (++$calls !== __NTH_CALL__) {
                return \stream_set_blocking($stream, $enable);
            }

            $GLOBALS['reached'][] = __FUNCTION__;

            return false;
        }
        PHP;

    private const string SHADOW_SELECT_FAILS = <<<'PHP'
        function stream_select(&$read, &$write, &$except, $seconds, $microseconds = null)
        {
            if (($GLOBALS['reached'] ?? []) !== []) {
                return \stream_select($read, $write, $except, $seconds, $microseconds);
            }

            $GLOBALS['reached'][] = __FUNCTION__;

            return false;
        }
        PHP;

    /**
     * Fails on the stream that actually carried bytes, not on the first stream
     * it is handed. That is what makes "which stream failed" an axis this file
     * can set: with a child writing only to stdout the failure falls on stdout,
     * with one writing only to stderr it falls on stderr, and neither depends on
     * the order `stream_select()` happens to report the two in.
     *
     * Reading for real first is what identifies the carrier. The bytes are then
     * dropped, which costs nothing: the module is about to be told the read
     * failed, and if it wrongly carries on, the next call delegates and the loop
     * still reaches EOF.
     */
    private const string SHADOW_CARRYING_READ_FAILS = <<<'PHP'
        function stream_get_contents($stream, $length = -1, $offset = -1)
        {
            $chunk = \stream_get_contents($stream, $length, $offset);
            if ($chunk === '' || $chunk === false || ($GLOBALS['reached'] ?? []) !== []) {
                return $chunk;
            }

            $GLOBALS['reached'][] = __FUNCTION__;

            return false;
        }
        PHP;

    /**
     * The notice is the point, not decoration. The module suppresses the write
     * with `@`, then reads `error_get_last()` to recover the errno, because that
     * message is the only place the stream layer exposes it to userland. Raising
     * one with an errno that is *not* 32 exercises the branch a real
     * non-`EPIPE` write failure would take; returning `false` silently would
     * instead land on the `??` fallback and leave that branch unmeasured.
     */
    private const string SHADOW_WRITE_FAILS = <<<'PHP'
        function fwrite($stream, $data, $length = null)
        {
            if (($GLOBALS['reached'] ?? []) !== []) {
                return \fwrite($stream, $data);
            }

            $GLOBALS['reached'][] = __FUNCTION__;
            trigger_error(
                'Write of ' . strlen($data) . ' bytes failed with errno=28 No space left on device',
                E_USER_NOTICE,
            );

            return false;
        }
        PHP;

    /**
     * Loads the module by path, the way the vendor-less callers load it, with
     * one shadow in place. Output goes out through `echo`, a language construct:
     * `fwrite()` is one of the names a case shadows, and a harness printing
     * through it would report nothing at all in that case.
     *
     * Three lines, in this order: the outcome, which shadow ran, and the
     * message. The message comes last because it may itself contain newlines.
     */
    private const string HARNESS_TEMPLATE = <<<'PHP'
        <?php

        namespace Qualimetrix\Subprocess;

        __SHADOW__

        require_once __MODULE_PATH__;

        try {
            $result = ChildProcess::run(__COMMAND__, null, __STDIN__);
            $outcome = 'RETURNED';
            $detail = 'exit code ' . $result['exitCode'];
        } catch (\Throwable $failure) {
            $outcome = $failure::class;
            $detail = $failure->getMessage();
        }

        echo $outcome, "\n", implode(',', $GLOBALS['reached'] ?? []), "\n", $detail;
        PHP;

    /** @return list<array{string, string}> */
    public static function providePrefixes(): array
    {
        return [
            ['START_FAILURE_PREFIX', ChildProcess::START_FAILURE_PREFIX],
            ['READ_FAILURE_PREFIX', ChildProcess::READ_FAILURE_PREFIX],
            ['WRITE_FAILURE_PREFIX', ChildProcess::WRITE_FAILURE_PREFIX],
        ];
    }

    /**
     * Every throw site, and for each the operation whose failure it guards, the
     * child it runs, the stdin it is given and the prefix the caller must get.
     *
     * The three read sites are separate entries although they share a prefix: a
     * wrong constant at one is invisible in the others. Beyond that, the spread
     * is what pins the axes named in the class docblock — the spawn and select
     * shadows appear with and without a payload, the read shadow appears against
     * a child that writes only to stdout and one that writes only to stderr, and
     * the non-blocking shadow is aimed at each serviced descriptor in turn.
     *
     * The write site takes a payload in every case that reaches it, and cannot
     * be measured without one: with no stdin the module closes that descriptor
     * at once and never writes. The payload is short deliberately, so that a
     * real `fwrite()` swallows it whole and the case reports `RETURNED` — the
     * red this file wants when a shadow fails to take.
     *
     * @return array<string, array{string, list<string>, string, non-empty-string}>
     */
    public static function provideInjectedFailures(): array
    {
        $writesToStdout = [\PHP_BINARY, '-r', 'fwrite(STDOUT, "o");'];
        $writesToStderr = [\PHP_BINARY, '-r', 'fwrite(STDERR, "e");'];

        return [
            'the spawn fails, with no stdin' => [
                self::SHADOW_SPAWN_FAILS,
                $writesToStdout,
                '',
                ChildProcess::START_FAILURE_PREFIX,
            ],
            'the spawn fails, with stdin to deliver' => [
                self::SHADOW_SPAWN_FAILS,
                $writesToStdout,
                'payload',
                ChildProcess::START_FAILURE_PREFIX,
            ],
            'the stdout pipe cannot be put into non-blocking mode' => [
                self::failingOnCall(1),
                $writesToStdout,
                '',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'the stderr pipe cannot be put into non-blocking mode' => [
                self::failingOnCall(2),
                $writesToStderr,
                '',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'the stdin pipe cannot be put into non-blocking mode, with stdin to deliver' => [
                self::failingOnCall(3),
                $writesToStdout,
                'payload',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'the select over the pipes fails, with no stdin' => [
                self::SHADOW_SELECT_FAILS,
                $writesToStdout,
                '',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'the select over the pipes fails, with stdin to deliver' => [
                self::SHADOW_SELECT_FAILS,
                $writesToStdout,
                'payload',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'the stdout pipe cannot be read' => [
                self::SHADOW_CARRYING_READ_FAILS,
                $writesToStdout,
                '',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'the stderr pipe cannot be read' => [
                self::SHADOW_CARRYING_READ_FAILS,
                $writesToStderr,
                '',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'a pipe cannot be read, with stdin to deliver' => [
                self::SHADOW_CARRYING_READ_FAILS,
                $writesToStdout,
                'payload',
                ChildProcess::READ_FAILURE_PREFIX,
            ],
            'the write to stdin fails for a reason other than EPIPE' => [
                self::SHADOW_WRITE_FAILS,
                $writesToStdout,
                'payload',
                ChildProcess::WRITE_FAILURE_PREFIX,
            ],
        ];
    }

    /**
     * The non-blocking shadow aimed at one descriptor, by the number of the call
     * that reaches it. Kept next to the table rather than inside the shadow so
     * that the shadow stays one function declaration, which
     * {@see self::operationOf()} refuses anything else.
     */
    private static function failingOnCall(int $nth): string
    {
        return str_replace('__NTH_CALL__', (string) $nth, self::SHADOW_SET_BLOCKING_FAILS);
    }

    #[Test]
    public function itGivesEveryFailureANonEmptyPrefix(): void
    {
        foreach (self::providePrefixes() as [$name, $prefix]) {
            self::assertNotSame('', $prefix, $name . ' must carry text; an empty prefix matches every message');
        }
    }

    /**
     * Pairwise distinctness and the prefix-of-another property are asserted
     * together because they fail together in the case worth catching: two
     * constants converging on one wording. Comparing every ordered pair also
     * covers the asymmetric half — `A` being a prefix of `B` says nothing
     * about `B` and `A`.
     */
    #[Test]
    public function itKeepsThePrefixesTellableApart(): void
    {
        $prefixes = self::providePrefixes();

        foreach ($prefixes as [$name, $prefix]) {
            foreach ($prefixes as [$otherName, $otherPrefix]) {
                if ($name === $otherName) {
                    continue;
                }

                self::assertFalse(
                    str_starts_with($otherPrefix, $prefix),
                    $name . ' is a prefix of ' . $otherName . ', so a caller dispatching on ' . $name
                    . ' would also accept a ' . $otherName . ' failure and report the wrong cause',
                );
            }
        }
    }

    /**
     * The assertions run outermost fact first, so that a case which measured
     * nothing says so instead of reporting a prefix mismatch: the harness has to
     * have finished, then it has to have reached the shadow, and only then is
     * the prefix it produced worth comparing.
     *
     * @param list<string> $command
     * @param non-empty-string $expectedPrefix
     */
    #[Test]
    #[DataProvider('provideInjectedFailures')]
    public function itReportsAFailureWithThePrefixOfTheOperationThatFailed(
        string $shadow,
        array $command,
        string $stdin,
        string $expectedPrefix,
    ): void {
        $operation = self::operationOf($shadow);
        $harness = $this->runWithShadow($shadow, $command, $stdin);

        self::assertSame(
            0,
            $harness['exitCode'],
            'The harness process did not finish cleanly, so it reported nothing about the prefix. Its stderr: '
            . $harness['stderr'],
        );

        $parts = explode("\n", $harness['stdout'], 3);
        self::assertCount(
            3,
            $parts,
            'The harness printed no outcome. Its stdout was ' . var_export($harness['stdout'], true)
            . ' and its stderr: ' . $harness['stderr'],
        );
        [$outcome, $reached, $detail] = $parts;

        self::assertSame(
            $operation,
            self::shortNameOf($reached),
            'This case did not measure what it claims to. It injects a failure of ' . $operation
            . '(), and that function has to be the one that failed -- an empty value here means the shadow was '
            . 'never reached, so the real function ran and the prefix below, if any, came from somewhere else. '
            . 'The harness reported outcome ' . var_export($outcome, true) . ' and '
            . var_export($detail, true),
        );
        self::assertSame(
            RuntimeException::class,
            $outcome,
            'The injected failure of ' . $operation . '() was reached but not reported as a '
            . RuntimeException::class . ', which the callers dispatching on a message prefix cannot see at all. '
            . '`RETURNED` means run() carried on past the failure instead of raising: the throw site guarding '
            . $operation . '() no longer throws. The harness said ' . var_export($detail, true),
        );
        self::assertStringStartsWith(
            $expectedPrefix,
            $detail,
            'The failure of ' . $operation . '() was raised with the wrong prefix, so every caller dispatching '
            . 'on it is told a cause that did not happen. Expected '
            . var_export($expectedPrefix, true) . ', got ' . var_export($detail, true),
        );
    }

    /**
     * The operation a shadow makes fail, read out of the shadow itself rather
     * than named a second time beside it. Two reasons, and the first is the
     * governing one: every spelling of a subprocess spawner's name outside the
     * module needs its own line-anchored entry in
     * `SubprocessReadsAreDrainedConcurrentlyTest::ENTRIES`, and naming the
     * operation again here would multiply those entries — each one going stale
     * the next time a line is inserted above it. Second, a name written twice
     * can disagree with itself; this one cannot.
     *
     * Refuses rather than guesses: a shadow is exactly one function declaration,
     * and anything else means the table above no longer holds what this method
     * assumes.
     */
    private static function operationOf(string $shadow): string
    {
        self::assertSame(
            1,
            preg_match_all('/^function (\\w+)\\(/m', $shadow, $matches),
            'A shadow must declare exactly one function, whose name is the operation the case makes fail: '
            . $shadow,
        );

        return $matches[1][0];
    }

    /**
     * `__FUNCTION__` inside the shadow reports the name the declaration got,
     * which is namespaced — the whole point being that it sits in the module's
     * namespace rather than the global one.
     */
    private static function shortNameOf(string $function): string
    {
        $separator = strrpos($function, '\\');

        return $separator === false ? $function : substr($function, $separator + 1);
    }

    /**
     * Runs the harness through the module itself, which is deliberate: the
     * transport is a plain successful run, a shape every other case in this
     * directory already covers, and a defect in it makes these cases red rather
     * than green. It also keeps this file from opening a child of its own.
     *
     * No deadline supervision, unlike the drain cases, and the reason is a
     * property of the shadows rather than of the module: each one fails once and
     * then delegates, so the harness terminates whether or not the module raises
     * at the site under measurement.
     *
     * @param list<string> $command
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runWithShadow(string $shadow, array $command, string $stdin): array
    {
        $harnessPath = tempnam(sys_get_temp_dir(), 'qmx-failure-prefix-harness-');
        self::assertIsString($harnessPath);

        try {
            $source = str_replace(
                ['__SHADOW__', '__MODULE_PATH__', '__COMMAND__', '__STDIN__'],
                [
                    $shadow,
                    var_export($this->modulePath(), true),
                    var_export($command, true),
                    var_export($stdin, true),
                ],
                self::HARNESS_TEMPLATE,
            );
            self::assertNotFalse(file_put_contents($harnessPath, $source));

            return ChildProcess::run([\PHP_BINARY, $harnessPath]);
        } finally {
            @unlink($harnessPath);
        }
    }

    /**
     * Resolved from this file's own directory rather than through the
     * autoloader, so that a copy of this directory measures the module beside
     * it. Measured, and only this far: a throw-site swap planted in a copy
     * reddens that copy's own run.
     */
    private function modulePath(): string
    {
        $path = realpath(\dirname(__DIR__) . '/ChildProcess.php');
        self::assertIsString($path);

        return $path;
    }
}
