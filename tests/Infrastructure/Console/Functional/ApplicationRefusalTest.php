<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

/**
 * `Application::doRun()`'s ladder, on the real binary, over a real process.
 *
 * Everything here needs an actual process exit code, an actual PHP fatal (or
 * its absence), or non-interactive stdin — none of which an in-process
 * `doRun()` call can show. `tests/Infrastructure/Console/Unit/ApplicationTest.php`
 * covers the clause logic itself, including the live-progress-frame case
 * (`SplitStreamConsoleOutput` gives that one a decorated stream without a
 * real terminal); this file checks the process boundary.
 */
#[CoversClass(Application::class)]
final class ApplicationRefusalTest extends TestCase
{
    private const int REFUSAL = 3;

    private const int INTERNAL_ERROR = 1;

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-app-refusal-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== '' && is_dir($this->fixture)) {
            self::removeDirectory($this->fixture);
        }
    }

    /**
     * A synthetic command throws a genuine `\Error` inside the application.
     * The subprocess proves the application catches it and returns the
     * internal-error status instead of exposing PHP's fatal-exit behavior.
     */
    #[Test]
    public function itCancelsExitCode255ForAnUncaughtPhpError(): void
    {
        $harness = $this->writeHarness(catchErrors: true);

        $run = $this->runScript($harness, ['boom']);

        self::assertSame(self::INTERNAL_ERROR, $run['exitCode']);
        self::assertSame('', $run['stdout']);
        self::assertNotSame('', $run['stderr']);
        self::assertStringNotContainsString('PHP Fatal error', $run['stderr']);
    }

    /**
     * `setCatchErrors()` controls Symfony's outer `run()` handler. This error
     * is thrown inside `doRun()`, where the application has its own handler;
     * the test keeps those two responsibilities distinct.
     */
    #[Test]
    public function itCatchesTheSameErrorEvenWithoutSetCatchErrors(): void
    {
        $harness = $this->writeHarness(catchErrors: false);

        $run = $this->runScript($harness, ['boom']);

        self::assertSame(self::INTERNAL_ERROR, $run['exitCode']);
    }

    #[Test]
    public function itRefusesAWorkingDirectoryThatIsAFile(): void
    {
        $file = $this->fixture . '/not-a-directory';
        file_put_contents($file, 'x');

        $run = $this->runBin(['-d', $file, 'rules']);

        self::assertSame(self::REFUSAL, $run['exitCode']);
    }

    #[Test]
    public function itRefusesAnUnreadableWorkingDirectoryWithoutAPhpWarning(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory permission bits.');
        }

        $unreadable = $this->fixture . '/locked';
        mkdir($unreadable, 0o000);

        $run = $this->runBin(['-d', $unreadable, 'rules']);

        self::assertSame(self::REFUSAL, $run['exitCode']);
        self::assertSame('', $run['stdout']);
        self::assertStringNotContainsString('Warning', $run['stderr']);

        chmod($unreadable, 0o755);
    }

    #[Test]
    public function itRefusesAnUnknownCommandNonInteractively(): void
    {
        $run = $this->runBin(['chek', '--no-interaction'], stdin: '');

        self::assertSame(self::REFUSAL, $run['exitCode']);
    }

    /**
     * Route 4: the interactive alternative to the case directly above.
     * Symfony's own `Application::doRunCommand()` asks "Do you want to run
     * X instead?" whenever exactly one alternative exists and the input is
     * interactive — a property `ArgvInput` defaults to `true` for and only
     * `--no-interaction`/`-n` (or `NO_INTERACTION`) turns off, regardless of
     * whether stdin is an actual TTY. So this reaches the question with a
     * plain piped, non-TTY stdin: no pty is required. An empty stream is a
     * valid answer to the question (it defaults to "no", same as declining),
     * and the run then exits the way declining the alternative always has —
     * outside the refusal ladder entirely, since the question is answered
     * and the command genuinely never ran; that exit code is not the subject
     * here; the reproduction of the question on stdout is.
     */
    #[Test]
    public function itAsksToRunTheAlternativeInteractivelyInsteadOfRefusingOutright(): void
    {
        $run = $this->runBin(['chek'], stdin: '');

        self::assertStringContainsString('Command "chek" is not defined.', $run['stdout']);
        self::assertStringContainsString('Do you want to run "check" instead?', $run['stdout']);
    }

    /**
     * The real `RulesCommand` refusal must reach the application ladder and
     * exit as a user configuration error; a unit test of either side alone
     * cannot prove the complete route.
     */
    #[Test]
    public function itRefusesAnUnknownRuleGroupThroughTheApplicationLadder(): void
    {
        $run = $this->runBin(['rules', '--group=bogus']);

        self::assertSame(self::REFUSAL, $run['exitCode']);
        self::assertNotSame('', $run['stderr']);
    }

    /**
     * The bare-`ConsoleExceptionInterface` route
     * ({@see \Qualimetrix\Tests\Infrastructure\Console\Unit\ApplicationTest::itReturnsRefusalExitCodeForABareInvalidArgumentExceptionWithNoCarrier()}
     * proves the mechanism synthetically) is asserted here as a real-input
     * fact about the surface, not just the mechanism: an unknown option is
     * refused through `Application::doRun()`'s ladder — never reaching a
     * command's own `execute()` — on three commands that do not share a
     * base class or a common option-validation call site.
     */
    #[Test]
    #[DataProvider('provideCommandsForTheUnknownOptionRoute')]
    public function itRefusesAnUnknownOptionOnDifferentCommandsThroughTheApplicationLadder(string $command): void
    {
        $run = $this->runBin([$command, '--this-option-does-not-exist']);

        self::assertSame(self::REFUSAL, $run['exitCode']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('option does not exist', $run['stderr']);
    }

    /** @return iterable<string, array{string}> */
    public static function provideCommandsForTheUnknownOptionRoute(): iterable
    {
        yield 'baseline:explain' => ['baseline:explain'];
        yield 'directives' => ['directives'];
        yield 'graph:export' => ['graph:export'];
    }

    /**
     * A refusal the outer ladder catches is enveloped exactly like one a
     * command catches: the requested format is on the command line whether or
     * not binding got far enough to read it.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideOuterLadderRefusalsUnderJson')]
    public function itEnvelopesAnOuterLadderRefusalWhenJsonWasRequested(array $arguments): void
    {
        $run = $this->runBin($arguments);

        self::assertSame(self::REFUSAL, $run['exitCode'], $run['stderr']);
        /** @var array{error?: string, exit_code?: int} $envelope */
        $envelope = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(self::REFUSAL, $envelope['exit_code'] ?? null);
        self::assertNotSame('', $envelope['error'] ?? '');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function provideOuterLadderRefusalsUnderJson(): iterable
    {
        yield 'unknown option' => [['check', 'src', '--format=json', '--no-such-option=1']];
        yield 'unknown option, separate value' => [['check', 'src', '--format', 'json', '--no-such-option']];
        yield 'unknown command' => [['no-such-command', '--format=json', '--no-interaction']];
        yield 'bad working directory' => [['check', 'src', '--format=sarif', '--working-dir=/nonexistent-qmx-dir']];
    }

    /**
     * The lawful neighbours: a format that is not JSON, and the short `-f`,
     * which the outer ladder cannot read because it means `--force` on
     * `hook:install` — both keep the prose refusal on stderr.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideOuterLadderRefusalsInProse')]
    public function itKeepsTheOuterLadderRefusalOnStderrWithoutAJsonFormat(array $arguments): void
    {
        $run = $this->runBin($arguments);

        self::assertSame(self::REFUSAL, $run['exitCode']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('option does not exist', $run['stderr']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function provideOuterLadderRefusalsInProse(): iterable
    {
        yield 'text format' => [['check', 'src', '--format=text', '--no-such-option']];
        yield 'dot format' => [['graph:export', '--format=dot', '--no-such-option']];
        yield 'short -f' => [['hook:install', '-f', '--no-such-option']];
    }

    #[Test]
    public function itPrintsATraceForAnInternalErrorUnderVerboseButNotForARefusal(): void
    {
        $harness = $this->writeHarness(catchErrors: true);

        $internal = $this->runScript($harness, ['boom', '-vvv']);
        self::assertSame(self::INTERNAL_ERROR, $internal['exitCode']);
        self::assertStringContainsString('Stack trace', $internal['stderr']);

        $refusal = $this->runBin(['rules', '--group=bogus', '-vvv']);
        self::assertSame(self::REFUSAL, $refusal['exitCode']);
        self::assertStringNotContainsString('Stack trace', $refusal['stderr']);
    }

    /**
     * A standalone script rather than a modified `bin/qmx`: it wires the
     * exact same `Application` class with a bare `ErrorStream` and
     * `RefusalPresenter` (no container needed — a `boom` command that throws
     * is the whole subject) and a single command that throws a genuine
     * `\Error`, so the process either fatals or does not depending only on
     * `setCatchErrors()`.
     */
    private function writeHarness(bool $catchErrors): string
    {
        $repoRoot = \dirname(__DIR__, 4);
        $path = $this->fixture . '/harness.php';
        $catch = $catchErrors ? 'true' : 'false';

        file_put_contents($path, <<<PHP
            <?php
            declare(strict_types=1);
            require '{$repoRoot}/vendor/autoload.php';

            use Qualimetrix\\Infrastructure\\Console\\Application;
            use Qualimetrix\\Infrastructure\\Console\\ErrorStream;
            use Qualimetrix\\Infrastructure\\Console\\Refusal\\RefusalPresenter;
            use Symfony\\Component\\Console\\Command\\Command;
            use Symfony\\Component\\Console\\Input\\InputInterface;
            use Symfony\\Component\\Console\\Output\\OutputInterface;

            \$errorStream = new ErrorStream();
            \$app = new Application(\$errorStream, new RefusalPresenter(\$errorStream));
            \$app->setCatchErrors({$catch});
            \$app->addCommand(new class extends Command {
                protected function configure(): void
                {
                    \$this->setName('boom');
                }

                protected function execute(InputInterface \$input, OutputInterface \$output): int
                {
                    throw new \\TypeError('synthetic crash for the 255-cancellation harness');
                }
            });

            exit(\$app->run());

            PHP);

        return $path;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runScript(string $scriptPath, array $arguments): array
    {
        return $this->execute(array_merge([\PHP_BINARY, $scriptPath], $arguments), '');
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runBin(array $arguments, string $stdin = ''): array
    {
        return $this->execute(array_merge([\PHP_BINARY, self::binPath()], $arguments), $stdin);
    }

    /**
     * @param list<string> $command
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function execute(array $command, string $stdin): array
    {
        return ChildProcess::run($command, $this->fixture, $stdin);
    }

    private static function binPath(): string
    {
        return \dirname(__DIR__, 4) . '/bin/qmx';
    }

    private static function removeDirectory(string $directory): void
    {
        foreach ((array) scandir($directory) as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                chmod($path, 0o755);
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
