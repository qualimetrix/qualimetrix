<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\OutputEncoding;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Qualimetrix\Tests\Infrastructure\Console\Support\SplitStreamConsoleOutput;

#[CoversClass(OutputEncoding::class)]
#[CoversClass(ErrorStream::class)]
final class OutputEncodingTest extends TestCase
{
    #[Test]
    public function itNamesTheEnvironmentSourceOfAnInvalidMode(): void
    {
        try {
            OutputEncoding::fromEnvironment('maybe');
            self::fail('Invalid environment value must be refused');
        } catch (\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal $refusal) {
            self::assertStringContainsString('environment variable QMX_ASCII', $refusal->sources()[0]->describe());
        }
    }

    #[Test]
    public function itDistinguishesFalseValuesFromAnEnabledMode(): void
    {
        foreach ([false, '', '0', 'false', 'no', 'off'] as $value) {
            self::assertSame(GlyphMode::Unicode, OutputEncoding::fromEnvironment($value));
        }
        foreach (['1', 'true', 'yes', 'on'] as $value) {
            self::assertSame(GlyphMode::Ascii, OutputEncoding::fromEnvironment($value));
        }
    }

    #[Test]
    public function itKeepsDirectWritersAndTheProgressSectionOnTheSameMode(): void
    {
        $output = new SplitStreamConsoleOutput(stderrDecorated: false);
        $owner = new ErrorStream();
        $owner->useGlyphMode(GlyphMode::Ascii);
        $owner->writer($output)->writeln("Café K\xFF — diagnostic");
        $owner->progressSection($output)?->writeln('█░▓');
        self::assertStringContainsString('Café K%FF - diagnostic', $output->errorOutputContent());
        self::assertStringContainsString('#.#', $output->errorOutputContent());
        self::assertTrue(mb_check_encoding($output->errorOutputContent(), 'UTF-8'));
    }

    #[Test]
    public function itPublishesBufferedDiagnosticsWithoutAProgressSection(): void
    {
        $output = new SplitStreamConsoleOutput(stderrDecorated: false);
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        $output->setErrorOutput($buffer);
        $owner = new ErrorStream();
        $owner->useGlyphMode(GlyphMode::Ascii);
        $owner->writer($output)->writeln("Café K\xFF — diagnostic");
        self::assertSame("Café K%FF - diagnostic\n", $buffer->fetch());
        self::assertNull($owner->progressSection($output));
    }

    #[Test]
    public function itRefusesADifferentModeAfterBindingOne(): void
    {
        $owner = new ErrorStream();
        $owner->useGlyphMode(GlyphMode::Ascii);
        $owner->useGlyphMode(GlyphMode::Ascii);
        $this->expectException(LogicException::class);
        $owner->useGlyphMode(GlyphMode::Unicode);
    }
}
