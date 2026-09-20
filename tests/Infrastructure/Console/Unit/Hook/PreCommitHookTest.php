<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Hook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;

#[CoversClass(PreCommitHook::class)]
final class PreCommitHookTest extends TestCase
{
    #[Test]
    public function itCarriesTheMarkerThatMakesAHookOurs(): void
    {
        self::assertTrue(PreCommitHook::isOurs(PreCommitHook::script('/usr/bin/qmx')));
    }

    #[Test]
    public function itDoesNotClaimAThirdPartyHook(): void
    {
        self::assertFalse(PreCommitHook::isOurs("#!/bin/bash\necho 'someone else'\n"));
    }

    #[Test]
    public function itSubstitutesTheBinaryAndLeavesNoPlaceholderBehind(): void
    {
        $script = PreCommitHook::script('/usr/bin/qmx');

        self::assertStringContainsString('QMX_BIN="/usr/bin/qmx"', $script);
        self::assertStringNotContainsString('@QMX_BINARY@', $script);
    }

    /**
     * A shell variable inside the template must reach the hook file spelled
     * as itself. A heredoc instead of a nowdoc would have PHP expand
     * `$QMX_BIN` and `$STAGED_FILES` to nothing, and the result is still a
     * valid shell script — it just checks no files.
     */
    #[Test]
    public function itLeavesTheShellsOwnVariablesForTheShell(): void
    {
        $script = PreCommitHook::script('/usr/bin/qmx');

        self::assertStringContainsString('$STAGED_FILES', $script);
        self::assertStringContainsString('$BASELINE_ARG', $script);
        self::assertStringContainsString('$EXIT_CODE', $script);
    }

    /**
     * The binary path is quoted in the generated shell, so a path with a
     * space in it stays one word. A home directory whose name carries a
     * space is ordinary on macOS, so this is not a corner case.
     */
    #[Test]
    public function itQuotesABinaryPathThatCarriesSpaces(): void
    {
        $script = PreCommitHook::script('/opt/a b/qmx');

        self::assertStringContainsString('QMX_BIN="/opt/a b/qmx"', $script);
        self::assertSame('', self::shellSyntaxError($script));
    }

    #[Test]
    public function itGeneratesAScriptTheShellAccepts(): void
    {
        self::assertSame('', self::shellSyntaxError(PreCommitHook::script('/usr/bin/qmx')));
    }

    /**
     * @return string bash's complaint, or an empty string when it has none
     */
    private static function shellSyntaxError(string $script): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-hook-');
        self::assertIsString($path);
        file_put_contents($path, $script);

        $process = proc_open(
            ['bash', '-n', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process, 'Could not start bash, so the template went unchecked.');

        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        unlink($path);

        return trim($error);
    }
}
