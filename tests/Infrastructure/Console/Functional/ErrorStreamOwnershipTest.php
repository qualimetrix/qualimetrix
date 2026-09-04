<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Tests\Infrastructure\Console\Support\SplitStreamConsoleOutput;
use Qualimetrix\Tests\Infrastructure\Console\Support\TerminalScreen;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One seam per case: each writer that can reach the error stream during a run
 * must go through the owner that draws the progress frame.
 *
 * The shape of every case is the same, and it is the shape the defect had. A
 * frame is on screen; a writer emits one line; a second frame is drawn. A
 * writer that went around the owner leaves its line *above* nothing the frame
 * knows about, so the frame's own erase — counted in its own lines — eats the
 * line and lands the frame in the wrong place. A writer that went through the
 * owner leaves the line permanently and the frame below it.
 *
 * Each case therefore asserts two things a byte comparison cannot: the line is
 * still on the replayed screen, and the frame is below it.
 */
#[CoversClass(ErrorStream::class)]
final class ErrorStreamOwnershipTest extends TestCase
{
    #[Test]
    public function itKeepsALoggerLineAboveTheFrame(): void
    {
        $output = self::terminalOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $errorStream = new ErrorStream();
        $frame = self::frameOn($errorStream, $output);

        $logger = (new RuntimeLoggerConfigurator(new LoggerFactory(), new LoggerHolder(), $errorStream))
            ->configure(self::input(), $output);
        $logger->debug('a line from the collection phase');

        self::assertSurvivesAboveFrame($output, $frame, 'a line from the collection phase');
    }

    #[Test]
    public function itKeepsAProfileSummaryAboveTheFrame(): void
    {
        $output = self::terminalOutput();
        $errorStream = new ErrorStream();
        $frame = self::frameOn($errorStream, $output);

        $report = self::createStub(ProfileReportInterface::class);
        $report->method('isEnabled')->willReturn(true);

        (new ProfilePresenter($report, errorStream: $errorStream))->present(
            self::input(['profile-format' => 'nonsense'], ['profile' => 'file']),
            $output,
        );

        self::assertSurvivesAboveFrame($output, $frame, 'Invalid profile format: nonsense');
    }

    #[Test]
    public function itKeepsAnUncaughtThrowableAboveNoFrameAtAll(): void
    {
        $output = self::terminalOutput();
        $errorStream = new ErrorStream();
        $frame = self::frameOn($errorStream, $output);

        // Symfony hands `renderThrowable()` the already-resolved error stream,
        // never the console output, which is why the owner is asked for the
        // writer it is already bound to.
        (new Application($errorStream))->renderThrowable(
            new RuntimeException('a failure with the frame still up'),
            $output->getErrorOutput(),
        );

        $screen = TerminalScreen::replay($output->errorOutputContent())->unwrappedText();

        self::assertStringContainsString('a failure with the frame still up', $screen);
        self::assertStringNotContainsString($frame, $screen, 'the frame must be cleared, not stranded above the trace');
    }

    #[Test]
    public function itDrawsTheFrameBelowEveryLineWrittenBeforeIt(): void
    {
        // The ordering guarantee itself: the diagnostic section is created on
        // binding, so it cannot end up below the frame regardless of which
        // writer touches the owner first.
        $output = self::terminalOutput();
        $errorStream = new ErrorStream();

        $errorStream->write($output, 'written before any frame exists');
        $frame = self::frameOn($errorStream, $output);
        $errorStream->write($output, 'written while the frame is up');

        self::assertSurvivesAboveFrame($output, $frame, 'written before any frame exists');
        self::assertSurvivesAboveFrame($output, $frame, 'written while the frame is up');
    }

    #[Test]
    public function itDropsDiagnosticsWhenTheOutputHasNoErrorChannel(): void
    {
        // The single fallback. Folding diagnostics into the payload is the one
        // outcome the two channels exist to prevent.
        $errorStream = new ErrorStream();
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $errorStream->write($output, 'must not reach the payload');

        self::assertSame('', $output->fetch());
        self::assertNull($errorStream->progressSection($output));
    }

    #[Test]
    public function itRendersAnUncaughtThrowableIntoAnOutputThatHasNoErrorChannel(): void
    {
        // The fallback drops *diagnostics*, not the message that ends the run.
        // A run bound to a single-channel output has no diagnostic writer at
        // all, and an uncaught throwable would then leave exit code 1 and an
        // empty screen — strictly worse than folding the trace into the one
        // channel the caller gave, which is what Symfony itself does.
        $errorStream = new ErrorStream();
        $output = new BufferedOutput();

        $application = new Application($errorStream);
        $application->setAutoExit(false);
        $application->addCommand(self::commandThatBindsThenThrows($errorStream, 'a failure with nowhere to go'));

        $exitCode = $application->run(new ArrayInput(['command' => 'boom']), $output);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('a failure with nowhere to go', $output->fetch());
    }

    private static function commandThatBindsThenThrows(ErrorStream $errorStream, string $message): Command
    {
        return new class ($errorStream, $message) extends Command {
            public function __construct(private readonly ErrorStream $errorStream, private readonly string $message)
            {
                parent::__construct('boom');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                // Binds the owner to this output, exactly as a real run does
                // before it fails.
                $this->errorStream->write($output, 'a diagnostic before the failure');

                throw new RuntimeException($this->message);
            }
        };
    }

    private static function frameOn(ErrorStream $errorStream, SplitStreamConsoleOutput $output): string
    {
        $section = $errorStream->progressSection($output);
        self::assertInstanceOf(ConsoleSectionOutput::class, $section);

        $frame = ' 0/20 [>---] 0%';
        $section->overwrite($frame);

        return $frame;
    }

    private static function assertSurvivesAboveFrame(
        SplitStreamConsoleOutput $output,
        string $frame,
        string $line,
    ): void {
        $screen = TerminalScreen::replay($output->errorOutputContent())->unwrappedText();

        self::assertStringContainsString($line, $screen, 'the line was erased by the frame');

        $linePosition = strpos($screen, $line);
        $framePosition = strrpos($screen, $frame);
        self::assertIsInt($linePosition);
        self::assertIsInt($framePosition);
        self::assertGreaterThan(
            $linePosition,
            $framePosition,
            'the frame must be redrawn below the line, not left above it',
        );
    }

    private static function terminalOutput(int $verbosity = OutputInterface::VERBOSITY_NORMAL): SplitStreamConsoleOutput
    {
        return new SplitStreamConsoleOutput(stderrDecorated: true, verbosity: $verbosity);
    }

    /**
     * @param array<string, string> $options
     * @param array<string, string> $values
     */
    private static function input(array $options = [], array $values = []): ArrayInput
    {
        $definition = new InputDefinition();
        foreach (array_keys($options) as $name) {
            $definition->addOption(new InputOption($name, null, InputOption::VALUE_REQUIRED));
        }
        foreach (array_keys($values) as $name) {
            if (!$definition->hasOption($name)) {
                $definition->addOption(new InputOption($name, null, InputOption::VALUE_OPTIONAL));
            }
        }

        $parameters = [];
        foreach ($options as $name => $value) {
            $parameters['--' . $name] = $value;
        }
        foreach ($values as $name => $value) {
            $parameters['--' . $name] = $value;
        }

        return new ArrayInput($parameters, $definition);
    }
}
