<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console\Progress;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Progress\ConsoleProgressBar;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleProgressBarTest extends TestCase
{
    #[Test]
    public function itSkipsProgressBarForFewFiles(): void
    {
        $section = self::section();
        $reporter = new ConsoleProgressBar($section, minFilesForProgress: 10);

        // Should not create progress bar for 5 files
        $reporter->start(5);
        $reporter->advance();
        $reporter->setMessage('test');
        $reporter->finish();

        self::assertSame('', self::contentOf($section));
    }

    #[Test]
    public function itDrawsInTheSectionItWasGiven(): void
    {
        $section = self::section();
        $reporter = new ConsoleProgressBar($section);

        $reporter->start(100);
        $reporter->advance();
        $reporter->finish();

        self::assertStringContainsString('0/100', self::contentOf($section));
    }

    #[Test]
    public function itHandlesAdvanceBeforeStart(): void
    {
        self::expectNotToPerformAssertions();

        $reporter = new ConsoleProgressBar(self::section());

        // Should not throw when advancing before start
        $reporter->advance();
        $reporter->setMessage('test');
        $reporter->finish();
    }

    #[Test]
    public function itCanBeFinishedMultipleTimes(): void
    {
        self::expectNotToPerformAssertions();

        $reporter = new ConsoleProgressBar(self::section());

        $reporter->start(5); // Too few files, progress bar not created
        $reporter->finish();
        $reporter->finish(); // Should not throw
    }

    private static function section(): ConsoleSectionOutput
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open an in-memory stream');
        }
        $sections = [];

        return new ConsoleSectionOutput(
            $stream,
            $sections,
            OutputInterface::VERBOSITY_NORMAL,
            true,
            new OutputFormatter(true),
        );
    }

    private static function contentOf(ConsoleSectionOutput $section): string
    {
        $stream = $section->getStream();
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
