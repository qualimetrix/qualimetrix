<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Tests\Infrastructure\Console\Support\SplitStreamConsoleOutput;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The three outcomes a run can end on, and where each one is written.
 *
 * A {@see ConfigurationRefusal} and a bare `InvalidArgumentException`-shaped
 * fallback both answer exit code 3, an internal error answers 1; a JSON
 * format gets the `{error, exit_code}` envelope on stdout, anything else gets
 * one framed sentence on stderr; every write survives `-q`; a trace is added
 * only for an internal error and only from `VERBOSITY_VERBOSE` up.
 */
#[CoversClass(RefusalPresenter::class)]
final class RefusalPresenterTest extends TestCase
{
    #[Test]
    public function itAnswersAConfigurationRefusalOnStderrWithCodeThree(): void
    {
        $output = self::terminalOutput();
        $refusal = ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--group'),
            'unknown group "bogus"',
        );

        $exit = $this->presenter()->refusal($output, null, $refusal);

        self::assertSame(3, $exit);
        self::assertSame('', $output->standardOutputContent());
        self::assertStringContainsString('Configuration error: unknown group "bogus"', $output->errorOutputContent());
    }

    #[Test]
    #[TestWith(['json'])]
    #[TestWith(['sarif'])]
    #[TestWith(['gitlab'])]
    #[TestWith(['metrics'])]
    #[TestWith(['health'])]
    #[TestWith(['suppressed'])]
    public function itAnswersAConfigurationRefusalAsAnEnvelopeOnEveryJsonFormat(string $format): void
    {
        $output = self::terminalOutput();
        $refusal = ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--group'),
            'unknown group "bogus"',
        );

        $exit = $this->presenter()->refusal($output, $format, $refusal);

        self::assertSame(3, $exit);
        self::assertSame('', $output->errorOutputContent());
        self::assertSame(
            ['error' => 'Configuration error: unknown group "bogus"', 'exit_code' => 3],
            json_decode($output->standardOutputContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function itAnswersAFallbackRefusalWithTheCaughtMessageUnframed(): void
    {
        // No "Configuration error:" prefix — this path carries no carrier and
        // no summary(), only the exception's own message.
        $output = self::terminalOutput();

        $exit = $this->presenter()->fallbackRefusal($output, null, new RuntimeException('bad value'));

        self::assertSame(3, $exit);
        self::assertStringContainsString('bad value', $output->errorOutputContent());
        self::assertStringNotContainsString('Configuration error:', $output->errorOutputContent());
    }

    #[Test]
    public function itAnswersAnInternalErrorWithTheInternalHeaderAndCodeOne(): void
    {
        $output = self::terminalOutput();

        $exit = $this->presenter()->internalError($output, null, new RuntimeException('boom'));

        self::assertSame(1, $exit);
        self::assertStringContainsString('Internal error: boom', $output->errorOutputContent());
    }

    #[Test]
    public function itAnswersAnInternalErrorAsAnEnvelopeUnderAJsonFormat(): void
    {
        $output = self::terminalOutput();

        $exit = $this->presenter()->internalError($output, 'json', new RuntimeException('boom'));

        self::assertSame(1, $exit);
        self::assertSame(
            ['error' => 'Internal error: boom', 'exit_code' => 1],
            json_decode($output->standardOutputContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function itOmitsTheTraceBelowVerboseVerbosity(): void
    {
        $output = self::terminalOutput(OutputInterface::VERBOSITY_NORMAL);

        $this->presenter()->internalError($output, null, new RuntimeException('boom'));

        self::assertStringNotContainsString('Stack trace', $output->errorOutputContent());
    }

    #[Test]
    public function itPrintsTheTraceFromVerboseUpwardsForAnInternalErrorOnly(): void
    {
        $output = self::terminalOutput(OutputInterface::VERBOSITY_VERBOSE);

        $this->presenter()->internalError($output, null, new RuntimeException('boom'));

        self::assertStringContainsString('Stack trace', $output->errorOutputContent());
    }

    #[Test]
    public function itNeverPrintsATraceForARefusal(): void
    {
        $output = self::terminalOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $refusal = ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--group'),
            'unknown group "bogus"',
        );

        $this->presenter()->refusal($output, null, $refusal);

        self::assertStringNotContainsString('Stack trace', $output->errorOutputContent());
    }

    #[Test]
    public function itKeepsTheRefusalSentenceVisibleUnderQuiet(): void
    {
        // The message that ends the run is not payload `-q` may swallow — only
        // the report and the progress frame are.
        $output = self::terminalOutput(OutputInterface::VERBOSITY_QUIET);
        $refusal = ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--group'),
            'unknown group "bogus"',
        );

        $this->presenter()->refusal($output, null, $refusal);

        self::assertStringContainsString('Configuration error: unknown group "bogus"', $output->errorOutputContent());
    }

    #[Test]
    public function itKeepsTheEnvelopeVisibleUnderQuiet(): void
    {
        $output = self::terminalOutput(OutputInterface::VERBOSITY_QUIET);
        $refusal = ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--group'),
            'unknown group "bogus"',
        );

        $exit = $this->presenter()->refusal($output, 'json', $refusal);

        self::assertSame(3, $exit);
        self::assertSame(
            ['error' => 'Configuration error: unknown group "bogus"', 'exit_code' => 3],
            json_decode($output->standardOutputContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A refusal message can embed raw CLI input the
     * user typed (an option value quoted back into the message, e.g.
     * `graph:export --direction=<garbage>`), and PHP argv bytes are not
     * guaranteed valid UTF-8. Before the fix, `json_encode(...,
     * JSON_THROW_ON_ERROR)` on such a message threw a `JsonException` out of
     * `writeEnvelope()`, escaping the command's own `catch
     * (ConfigurationRefusal)` block and landing in the outer ladder as an
     * internal error — exit code 1 instead of the exit code 3 this refusal
     * had already committed to returning. `JSON_INVALID_UTF8_SUBSTITUTE`
     * keeps the envelope valid JSON instead.
     */
    #[Test]
    public function itKeepsTheEnvelopeParseableForAMessageContainingInvalidUtf8(): void
    {
        $output = self::terminalOutput();
        $refusal = ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--direction'),
            "Unknown direction \"\xff\xfe\". Supported directions: LR, TB, RL, BT.",
        );

        $exit = $this->presenter()->refusal($output, 'json', $refusal);

        self::assertSame(3, $exit);
        $decoded = json_decode($output->standardOutputContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(3, $decoded['exit_code']);
        self::assertStringContainsString('Configuration error: Unknown direction', $decoded['error']);
    }

    #[Test]
    public function itStopsALiveProgressFrameBeforePresenting(): void
    {
        $output = self::terminalOutput();
        $errorStream = new ErrorStream();
        $before = $errorStream->progressSection($output);
        self::assertInstanceOf(ConsoleSectionOutput::class, $before);

        (new RefusalPresenter($errorStream))->internalError($output, null, new RuntimeException('boom'));

        // stopProgress() forgets the section; asking again draws a fresh one
        // rather than handing back the frame that was live during the write.
        $after = $errorStream->progressSection($output);
        self::assertNotSame($before, $after);
    }

    private function presenter(): RefusalPresenter
    {
        return new RefusalPresenter(new ErrorStream());
    }

    private static function terminalOutput(int $verbosity = OutputInterface::VERBOSITY_NORMAL): SplitStreamConsoleOutput
    {
        return new SplitStreamConsoleOutput(stderrDecorated: false, verbosity: $verbosity);
    }
}
