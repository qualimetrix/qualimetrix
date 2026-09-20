<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Hook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

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
    public function itLeavesNoPlaceholderBehind(): void
    {
        self::assertStringNotContainsString('@QMX_BINARY@', PreCommitHook::script('/usr/bin/qmx'));
    }

    /**
     * A shell variable inside the template must reach the hook file spelled
     * as itself. A heredoc instead of a nowdoc would have PHP expand
     * `$BASELINE_ADVICE` and `$EXIT_CODE` to nothing, and the result is still
     * a valid shell script — it just checks no files and reports nothing.
     */
    #[Test]
    public function itLeavesTheShellsOwnVariablesForTheShell(): void
    {
        $script = PreCommitHook::script('/usr/bin/qmx');

        self::assertStringContainsString('${STAGED_FILES[@]}', $script);
        self::assertStringContainsString('$BASELINE_ADVICE', $script);
        self::assertStringContainsString('$EXIT_CODE', $script);
    }

    #[Test]
    public function itGeneratesAScriptTheShellAccepts(): void
    {
        self::assertSame('', self::shellSyntaxError(PreCommitHook::script('/usr/bin/qmx')));
    }

    /**
     * The binary path must arrive in the hook as itself, whatever it carries.
     *
     * The oracle runs the assignment and reads the variable back, rather than
     * asking whether the file parses. Parsing is blind to exactly the two
     * cases that matter: `$HOME` and `$(id -u)` in a directory name both
     * parse, and both change the value — the second by executing a command,
     * on every commit.
     */
    #[Test]
    #[DataProvider('provideHostileBinaryPaths')]
    public function itDeliversTheBinaryPathVerbatim(string $path): void
    {
        $script = PreCommitHook::script($path);

        self::assertSame('', self::shellSyntaxError($script), 'The generated hook is not valid shell.');
        self::assertSame($path, self::binaryTheShellReads($script));
    }

    /** @return iterable<string, array{string}> */
    public static function provideHostileBinaryPaths(): iterable
    {
        yield 'plain' => ['/usr/local/bin/qmx'];
        yield 'space' => ['/opt/a b/qmx'];
        yield 'double quote' => ['/opt/we"ird/qmx'];
        yield 'single quote' => ['/opt/it\'s/qmx'];
        yield 'dollar' => ['/opt/$HOME/qmx'];
        yield 'command substitution' => ['/opt/$(id -u)/qmx'];
        yield 'backtick' => ['/opt/`id -u`/qmx'];
        yield 'backslash' => ['/opt/back\\slash/qmx'];
    }

    /**
     * What `$QMX_BIN` holds after the shell has read the hook's assignment.
     */
    private static function binaryTheShellReads(string $script): string
    {
        $assignment = '';

        foreach (explode("\n", $script) as $line) {
            if (str_starts_with($line, 'QMX_BIN=')) {
                $assignment = $line;

                break;
            }
        }

        self::assertNotSame('', $assignment, 'The hook assigns no QMX_BIN at all.');

        return trim(self::runBash($assignment . "\nprintf '%s' \"\$QMX_BIN\"")[0]);
    }

    /**
     * @return string bash's complaint, or an empty string when it has none
     */
    private static function shellSyntaxError(string $script): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-hook-');
        self::assertIsString($path);
        file_put_contents($path, $script);

        $error = self::runBash(null, ['bash', '-n', $path])[1];

        unlink($path);

        return trim($error);
    }

    /**
     * @param list<string>|null $command defaults to running $script through bash
     *
     * @return array{string, string} stdout and stderr
     */
    private static function runBash(?string $script, ?array $command = null): array
    {
        $result = ChildProcess::run($command ?? ['bash', '-s'], null, (string) $script);

        return [$result['stdout'], $result['stderr']];
    }
}
