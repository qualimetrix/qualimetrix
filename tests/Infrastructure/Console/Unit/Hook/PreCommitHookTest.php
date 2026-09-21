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
    /** @var list<string> */
    private array $temporaryDirectories = [];

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

    #[Test]
    public function itSkipsAnalysisWhenNoPhpFilesAreStaged(): void
    {
        [$result, $gitInvocation, $qmxInvocation] = $this->executeGeneratedHook([]);

        self::assertSame(0, $result['exitCode']);
        self::assertFileExists($gitInvocation);
        self::assertFileDoesNotExist($qmxInvocation);
    }

    #[Test]
    public function itPassesStagedPathsContainingSpacesAndNewlinesAsSingleArguments(): void
    {
        $stagedPaths = [
            'src/space in name.php',
            "src/line\nbreak.php",
        ];
        [$result, , $qmxInvocation, $qmxArguments] = $this->executeGeneratedHook($stagedPaths);

        self::assertSame(0, $result['exitCode']);
        self::assertFileExists($qmxInvocation);
        self::assertSame(['check', ...$stagedPaths], self::readNulDelimitedArguments($qmxArguments));
    }

    #[Test]
    public function itFailsClosedWhenGitEnumerationFailsAfterWritingPaths(): void
    {
        [$result, $gitInvocation, $qmxInvocation] = $this->executeGeneratedHook(
            ['src/partial.php'],
            gitExitCode: 19,
        );

        self::assertSame(19, $result['exitCode']);
        self::assertFileExists($gitInvocation);
        self::assertFileDoesNotExist($qmxInvocation);
        self::assertStringContainsString('Could not enumerate staged PHP files (exit 19). Nothing was analysed.', $result['stdout']);
    }

    #[Test]
    public function itFailsBeforeGitWhenTheTemporaryFileCannotBeCreated(): void
    {
        [$result, $gitInvocation, $qmxInvocation] = $this->executeGeneratedHook(
            ['src/never-read.php'],
            mktempExitCode: 23,
        );

        self::assertSame(23, $result['exitCode']);
        self::assertFileDoesNotExist($gitInvocation);
        self::assertFileDoesNotExist($qmxInvocation);
        self::assertStringContainsString('Could not create a temporary staged-file list (exit 23). Nothing was analysed.', $result['stdout']);
    }

    #[Test]
    public function itFailsClosedWhenTheStagedFileListCannotBeRead(): void
    {
        [$result, $gitInvocation, $qmxInvocation] = $this->executeGeneratedHook(
            ['src/never-read.php'],
            replaceStagedFileListWithDirectory: true,
        );

        self::assertSame(1, $result['exitCode']);
        self::assertFileExists($gitInvocation);
        self::assertFileDoesNotExist($qmxInvocation);
        self::assertStringContainsString('Could not read the staged-file list. Nothing was analysed.', $result['stdout']);
    }

    #[Test]
    #[DataProvider('provideInterruptedReads')]
    public function itFailsClosedWhenReadingStopsBeforeTheCompleteStagedFileList(
        int $successfulReadsBeforeFailure,
        string $expectedPath,
    ): void {
        [$result, $gitInvocation, $qmxInvocation] = $this->executeGeneratedHook(
            ['src/first.php', 'src/second.php'],
            readFailureAfter: $successfulReadsBeforeFailure,
        );

        self::assertSame(1, $result['exitCode']);
        self::assertFileExists($gitInvocation);
        self::assertFileDoesNotExist($qmxInvocation);
        self::assertStringContainsString('Could not read the complete staged-file list. Nothing was analysed.', $result['stdout']);
        self::assertStringNotContainsString($expectedPath, $result['stdout']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideInterruptedReads(): iterable
    {
        yield 'before the first entry' => [0, 'src/first.php'];
        yield 'after the first entry' => [1, 'src/second.php'];
    }

    #[Test]
    #[DataProvider('provideQmxFailureExitCodes')]
    public function itPropagatesQmxFailureExitCodes(int $exitCode, string $expectedDiagnostic): void
    {
        [$result, , $qmxInvocation] = $this->executeGeneratedHook(
            ['src/staged.php'],
            qmxExitCode: $exitCode,
        );

        self::assertSame($exitCode, $result['exitCode']);
        self::assertFileExists($qmxInvocation);
        self::assertStringContainsString($expectedDiagnostic, $result['stdout']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideQmxFailureExitCodes(): iterable
    {
        yield 'warnings' => [1, '❌ Qualimetrix found issues.'];
        yield 'errors' => [2, '❌ Qualimetrix found issues.'];
        yield 'configuration error' => [3, '❌ Qualimetrix found issues.'];
        yield 'incomplete analysis' => [4, '❌ Qualimetrix found issues.'];
        yield 'not executable' => [126, '(exit 126). Nothing was analysed.'];
        yield 'not found' => [127, '(exit 127). Nothing was analysed.'];
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

    /**
     * @param list<string> $stagedPaths
     *
     * @return array{array{stdout: string, stderr: string, exitCode: int}, string, string, string}
     */
    private function executeGeneratedHook(
        array $stagedPaths,
        int $gitExitCode = 0,
        int $qmxExitCode = 0,
        ?int $mktempExitCode = null,
        bool $replaceStagedFileListWithDirectory = false,
        ?int $readFailureAfter = null,
    ): array {
        $workspace = $this->createTemporaryDirectory();
        $binDirectory = $workspace . '/bin';
        self::assertTrue(mkdir($binDirectory, 0700));

        $gitInvocation = $workspace . '/git-invoked';
        $qmxInvocation = $workspace . '/qmx-invoked';
        $qmxArguments = $workspace . '/qmx-arguments';
        $qmxBinary = $workspace . '/qmx';
        $hookPath = $workspace . '/pre-commit';
        $bashEnvironment = $workspace . '/bash-env';

        $this->writeExecutable(
            $binDirectory . '/git',
            $this->fakeGitScript($stagedPaths, $gitExitCode, $replaceStagedFileListWithDirectory),
        );
        $this->writeExecutable($qmxBinary, $this->fakeQmxScript());
        if ($mktempExitCode !== null) {
            $this->writeExecutable($binDirectory . '/mktemp', "#!/bin/bash\nexit $mktempExitCode\n");
        }
        self::assertNotFalse(file_put_contents($hookPath, PreCommitHook::script($qmxBinary)));
        self::assertTrue(chmod($hookPath, 0700));

        if ($readFailureAfter !== null) {
            self::assertNotFalse(file_put_contents($bashEnvironment, $this->failingReadFunction($readFailureAfter)));
        }

        $path = getenv('PATH');
        self::assertIsString($path);
        $result = ChildProcess::run(
            ['/bin/bash', $hookPath],
            $workspace,
            environment: [
                'GIT_INVOCATION_FILE' => $gitInvocation,
                'PATH' => $binDirectory . ':' . $path,
                'QMX_ARGUMENTS_FILE' => $qmxArguments,
                'QMX_EXIT' => (string) $qmxExitCode,
                'QMX_INVOCATION_FILE' => $qmxInvocation,
                'TMPDIR' => $workspace,
                ...($readFailureAfter === null ? [] : ['BASH_ENV' => $bashEnvironment]),
            ],
        );

        return [$result, $gitInvocation, $qmxInvocation, $qmxArguments];
    }

    /** @param list<string> $stagedPaths */
    private function fakeGitScript(array $stagedPaths, int $exitCode, bool $replaceStagedFileListWithDirectory): string
    {
        $script = <<<'SH'
            #!/bin/bash
            if [ -n "${GIT_INVOCATION_FILE:-}" ]; then
                : > "$GIT_INVOCATION_FILE"
            fi

            SH;

        if ($stagedPaths !== []) {
            $arguments = implode(' ', array_map(self::shellLiteral(...), $stagedPaths));
            $script .= "printf '%s\\0' $arguments\n";
        }

        if ($replaceStagedFileListWithDirectory) {
            $script .= <<<'SH'
                for staged_file_list in "$TMPDIR"/qmx-pre-commit.*; do
                    rm -f -- "$staged_file_list"
                    mkdir -- "$staged_file_list"
                done

                SH;
        }

        return $script . "exit $exitCode\n";
    }

    private function fakeQmxScript(): string
    {
        return <<<'SH'
            #!/bin/bash
            if [ -n "${QMX_INVOCATION_FILE:-}" ]; then
                : > "$QMX_INVOCATION_FILE"
            fi
            printf '%s\0' "$@" > "$QMX_ARGUMENTS_FILE"
            exit "$QMX_EXIT"
            SH;
    }

    private function failingReadFunction(int $successfulReadsBeforeFailure): string
    {
        return <<<SH
            QMX_TEST_READ_CALLS=0
            read() {
                if [ "\$QMX_TEST_READ_CALLS" -ge "$successfulReadsBeforeFailure" ]; then
                    return 1
                fi

                QMX_TEST_READ_CALLS=\$((QMX_TEST_READ_CALLS + 1))
                builtin read "\$@"
            }
            SH;
    }

    /** @return list<string> */
    private function readNulDelimitedArguments(string $path): array
    {
        $arguments = file_get_contents($path);
        self::assertIsString($arguments);
        self::assertStringEndsWith("\0", $arguments);

        return \array_slice(explode("\0", $arguments), 0, -1);
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/qmx-pre-commit-hook-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function writeExecutable(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0700));
    }

    private static function shellLiteral(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeTemporaryDirectory($directory);
        }

        parent::tearDown();
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTemporaryDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
