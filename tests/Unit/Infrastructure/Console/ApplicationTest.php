<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\ConsoleExitCode;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Tests\Infrastructure\Console\Support\RestoresShellVerbosityEnvironment;
use Qualimetrix\Tests\Infrastructure\Console\Support\SplitStreamConsoleOutput;
use Qualimetrix\Tests\Infrastructure\Console\Support\TerminalScreen;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Exception\LogicException as ConsoleLogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `doRun()`'s ladder (`01-refusal-exit-ladder.md` §2.4), exercised in-process
 * against a plain {@see RefusalPresenter} — no container needed, because the
 * presenter's own framing is not this class's contract to prove.
 *
 * `--working-dir` refusals never reach the final `Throwable` clause on their
 * own (they are thrown as a {@see ConfigurationRefusal}, caught by the first
 * clause); a command that throws is what exercises the other three.
 */
#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    use RestoresShellVerbosityEnvironment;

    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        if ($cwd === false) {
            self::fail('Cannot get current directory');
        }
        $this->originalCwd = $cwd;
        $this->snapshotShellVerbosityEnvironment();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->restoreShellVerbosityEnvironment();
    }

    #[Test]
    public function itChangesTheWorkingDirectoryWhenWorkingDirIsGiven(): void
    {
        $tempDir = sys_get_temp_dir();
        $resolved = realpath($tempDir);
        self::assertNotFalse($resolved);

        $app = self::application();
        $app->setAutoExit(false);

        $app->doRun(
            new ArrayInput(['--working-dir' => $tempDir, 'command' => 'list']),
            new NullOutput(),
        );

        self::assertSame($resolved, getcwd());
    }

    #[Test]
    public function itLeavesTheWorkingDirectoryUnchangedWhenWorkingDirIsOmitted(): void
    {
        $before = getcwd();

        $app = self::application();
        $app->setAutoExit(false);

        $app->doRun(new ArrayInput(['command' => 'list']), new NullOutput());

        self::assertSame($before, getcwd());
    }

    #[Test]
    public function itReturnsRefusalExitCodeForAnInvalidWorkingDirectoryInsteadOfThrowing(): void
    {
        $app = self::application();
        $app->setAutoExit(false);

        $exitCode = $app->doRun(
            new ArrayInput(['--working-dir' => '/nonexistent/path/xyz']),
            new NullOutput(),
        );

        self::assertSame(ConsoleExitCode::Refusal->value, $exitCode);
    }

    #[Test]
    public function itReturnsRefusalExitCodeForAnUnreadableWorkingDirectoryWithoutChdir(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory permission bits, so chmod 000 refuses nothing to run as root.');
        }

        $unreadable = sys_get_temp_dir() . '/qmx-app-ladder-' . bin2hex(random_bytes(6));
        mkdir($unreadable, 0o000);

        try {
            $app = self::application();
            $app->setAutoExit(false);

            $exitCode = $app->doRun(
                new ArrayInput(['--working-dir' => $unreadable]),
                new NullOutput(),
            );

            self::assertSame(ConsoleExitCode::Refusal->value, $exitCode);
            // A refusal from the is_readable() guard never calls chdir(), so
            // the process working directory is untouched — the boundary this
            // clause exists to prove (`01-refusal-exit-ladder.md` §2.4).
            self::assertNotSame($unreadable, getcwd());
        } finally {
            chmod($unreadable, 0o755);
            rmdir($unreadable);
        }
    }

    #[Test]
    public function itReturnsRefusalExitCodeForAConfigurationRefusalThrownBeforeExecute(): void
    {
        // Commands load lazily through a command loader
        // (`ContainerCommandLoader` in production); a refusal thrown while
        // building one — from a constructor or `configure()` — has no
        // command-level ladder on the stack yet, so it is this outermost
        // ladder that must catch it (`01-refusal-exit-ladder.md` §2.4).
        $app = self::application();
        $app->setAutoExit(false);
        $app->setCommandLoader(new FactoryCommandLoader([
            'refuses-early' => static fn(): Command => throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--bogus'),
                'a refusal raised before execute() runs',
            ),
        ]));

        $exitCode = $app->doRun(
            new ArrayInput(['command' => 'refuses-early']),
            new NullOutput(),
        );

        self::assertSame(ConsoleExitCode::Refusal->value, $exitCode);
    }

    #[Test]
    public function itReturnsRefusalExitCodeForAnUnknownCommandNonInteractively(): void
    {
        $app = self::application();
        $app->setAutoExit(false);

        $input = new ArrayInput(['command' => 'chek']);
        $input->setInteractive(false);

        $exitCode = $app->doRun($input, new NullOutput());

        self::assertSame(ConsoleExitCode::Refusal->value, $exitCode);
    }

    #[Test]
    public function itReturnsRefusalExitCodeForABareInvalidArgumentExceptionWithNoCarrier(): void
    {
        $app = self::application();
        $app->setAutoExit(false);
        $app->addCommand(self::commandThatThrows(new InvalidArgumentException('no carrier behind this one')));

        $exitCode = $app->doRun(
            new ArrayInput(['command' => 'throws']),
            new NullOutput(),
        );

        self::assertSame(ConsoleExitCode::Refusal->value, $exitCode);
    }

    #[Test]
    public function itReturnsInternalErrorExitCodeForAnUnrelatedThrowable(): void
    {
        $app = self::application();
        $app->setAutoExit(false);
        $app->addCommand(self::commandThatThrows(new RuntimeException('a product defect')));

        $exitCode = $app->doRun(
            new ArrayInput(['command' => 'throws']),
            new NullOutput(),
        );

        self::assertSame(ConsoleExitCode::InternalError->value, $exitCode);
    }

    /**
     * X15 review, mechanism C: {@see ConsoleLogicException}
     * implements {@see \Symfony\Component\Console\Exception\ExceptionInterface}
     * (the same interface `CommandNotFoundException`/`InvalidOptionException`
     * implement) but is thrown only on a malformed command *declaration* — a
     * defect in this project's own command wiring, never something a user's
     * input could trigger. Before the fix it was caught by the broad
     * `ConsoleExceptionInterface` clause and answered with the round's
     * user-input code (3); it must answer with the internal-error code (1)
     * instead, same as any other product defect.
     */
    #[Test]
    public function itReturnsInternalErrorExitCodeForAConsoleLogicException(): void
    {
        $app = self::application();
        $app->setAutoExit(false);
        $app->addCommand(self::commandThatThrows(
            new ConsoleLogicException('an option was declared twice'),
        ));

        $exitCode = $app->doRun(
            new ArrayInput(['command' => 'throws']),
            new NullOutput(),
        );

        self::assertSame(ConsoleExitCode::InternalError->value, $exitCode);
    }

    /**
     * X15 review, mechanism B1: Symfony's `--silent` sets
     * `OutputInterface::VERBOSITY_SILENT` (8), a level `Output::write()`
     * cannot address — its bitmask lookup only recognises
     * `VERBOSITY_QUIET|NORMAL|VERBOSE|VERY_VERBOSE|DEBUG`, so once the
     * output's verbosity is silent, nothing written at any verbosity
     * survives, including {@see RefusalPresenter}'s `VERBOSITY_QUIET`
     * writes. `Application::configureIO()` demotes it to `VERBOSITY_QUIET`
     * so the run-ending message keeps the guarantee rule 3 of
     * `00-overview.md` makes for it.
     */
    #[Test]
    public function itDemotesSilentVerbosityToQuiet(): void
    {
        $app = self::application();
        $input = new ArrayInput(['--silent' => true]);
        $output = new BufferedOutput();

        $method = new ReflectionMethod(Application::class, 'configureIO');
        $method->invoke($app, $input, $output);

        self::assertSame(OutputInterface::VERBOSITY_QUIET, $output->getVerbosity());
    }

    /**
     * End-to-end companion to {@see self::itDemotesSilentVerbosityToQuiet()}:
     * proves the demotion actually keeps a refusal message alive under
     * `--silent`, not just that the verbosity constant changes.
     */
    #[Test]
    public function itStillDeliversARefusalMessageUnderSilent(): void
    {
        $app = self::application();
        $app->setAutoExit(false);
        $app->addCommand(self::commandThatThrows(ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--bogus'),
            'a refusal that must survive --silent',
        )));

        $input = new ArrayInput(['command' => 'throws', '--silent' => true]);
        $output = new BufferedOutput();

        $method = new ReflectionMethod(Application::class, 'configureIO');
        $method->invoke($app, $input, $output);

        $exitCode = $app->doRun($input, $output);

        self::assertSame(ConsoleExitCode::Refusal->value, $exitCode);
        self::assertStringContainsString('a refusal that must survive --silent', $output->fetch());
    }

    #[Test]
    public function itIgnoresTheCaughtThrowablesOwnCode(): void
    {
        // `JsonException('x', JSON_ERROR_UTF8)` carries a non-zero code — 5,
        // not 1 — which Symfony's own convention would surface as the process
        // exit code: exactly the collision with `directives`' "run
        // incomplete" (4) this ladder exists to avoid
        // (`01-refusal-exit-ladder.md` §2.4). The assertion below checks the
        // exact value (1), which is what makes this a claim about the code
        // rather than merely that some code came back.
        $app = self::application();
        $app->setAutoExit(false);
        $app->addCommand(self::commandThatThrows(new JsonException('x', \JSON_ERROR_UTF8)));

        $exitCode = $app->doRun(
            new ArrayInput(['command' => 'throws']),
            new NullOutput(),
        );

        self::assertSame(ConsoleExitCode::InternalError->value, $exitCode);
    }

    #[Test]
    public function itFoldsTheMessageIntoAnOutputWithNoErrorChannelRatherThanDroppingIt(): void
    {
        // The exemption the presenter carries for the message that ends a
        // run (`RefusalPresenter::writeStderr()`, `ErrorStream::boundWriter()`):
        // an embedder bound to a single-channel output still gets the
        // sentence, rather than exit code 1 and zero bytes anywhere
        // (`00-overview.md` rule 3).
        $app = self::application();
        $app->setAutoExit(false);
        $app->addCommand(self::commandThatThrows(new RuntimeException('not dropped, folded into the one channel')));

        $output = new BufferedOutput();
        $app->doRun(new ArrayInput(['command' => 'throws']), $output);

        self::assertStringContainsString('not dropped, folded into the one channel', $output->fetch());
    }

    #[Test]
    public function itLeavesTheRefusalSentenceReadableAboveALiveProgressFrame(): void
    {
        // A refusal reaching the ladder while a progress frame is still on
        // screen — the case `01-refusal-exit-ladder.md` §3 moved
        // `stopProgress()` into the presenter for. `RefusalPresenterTest`
        // (P01-1) proves the presenter's own contract in isolation; this
        // proves the wiring this package adds does not lose it — the same
        // frame `Application` was constructed with is the frame the ladder's
        // presenter clears.
        $output = new SplitStreamConsoleOutput(stderrDecorated: true);
        $errorStream = new ErrorStream();

        $section = $errorStream->progressSection($output);
        self::assertInstanceOf(ConsoleSectionOutput::class, $section);
        $frame = ' 12/20 [=====>----------------------]  60%';
        $section->overwrite($frame);

        $app = self::application($errorStream);
        $app->setAutoExit(false);
        $app->addCommand(self::commandThatThrows(ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--bogus'),
            'a refusal raised while the frame above is still live',
        )));

        $exitCode = $app->doRun(new ArrayInput(['command' => 'throws']), $output);

        self::assertSame(ConsoleExitCode::Refusal->value, $exitCode);

        $screen = TerminalScreen::replay($output->errorOutputContent())->unwrappedText();
        self::assertStringContainsString(
            'a refusal raised while the frame above is still live',
            $screen,
            'the refusal sentence was erased by the frame instead of surviving above it',
        );
        self::assertStringNotContainsString(
            $frame,
            $screen,
            'the frame must be cleared before the refusal is printed, not left stranded above it',
        );
    }

    private static function application(?ErrorStream $errorStream = null): Application
    {
        $errorStream ??= new ErrorStream();

        // One shared instance, matching production wiring
        // (`01-refusal-exit-ladder.md` §3, "Как предъявитель попадает в
        // Application"): two independent `ErrorStream`s would let the
        // presenter clear a progress frame Application never drew on, or
        // vice versa.
        return new Application($errorStream, new RefusalPresenter($errorStream));
    }

    private static function commandThatThrows(Throwable $failure): Command
    {
        return new class ($failure) extends Command {
            public function __construct(private readonly Throwable $failure)
            {
                parent::__construct('throws');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw $this->failure;
            }
        };
    }
}
