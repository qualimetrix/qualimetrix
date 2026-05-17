<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console\Progress;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Progress\ConsoleProgressBar;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleProgressBarTest extends TestCase
{
    #[Test]
    public function itSkipsProgressBarForFewFiles(): void
    {
        self::expectNotToPerformAssertions();

        $output = self::createStub(OutputInterface::class);
        $reporter = new ConsoleProgressBar($output, minFilesForProgress: 10);

        // Should not create progress bar for 5 files
        $reporter->start(5);
        $reporter->advance();
        $reporter->setMessage('test');
        $reporter->finish();
    }

    #[Test]
    public function itSkipsProgressBarForNonConsoleOutput(): void
    {
        // BufferedOutput is not ConsoleOutputInterface
        $output = new BufferedOutput();
        $reporter = new ConsoleProgressBar($output);

        // Should not create progress bar for non-console output
        $reporter->start(100);
        $reporter->advance();
        $reporter->setMessage('test');
        $reporter->finish();

        // Output should be empty (no progress bar)
        self::assertSame('', $output->fetch());
    }

    #[Test]
    public function itHandlesAdvanceBeforeStart(): void
    {
        self::expectNotToPerformAssertions();

        $output = self::createStub(OutputInterface::class);
        $reporter = new ConsoleProgressBar($output);

        // Should not throw when advancing before start
        $reporter->advance();
        $reporter->setMessage('test');
        $reporter->finish();
    }

    #[Test]
    public function itCanBeFinishedMultipleTimes(): void
    {
        self::expectNotToPerformAssertions();

        $output = self::createStub(OutputInterface::class);
        $reporter = new ConsoleProgressBar($output);

        $reporter->start(5); // Too few files, progress bar not created
        $reporter->finish();
        $reporter->finish(); // Should not throw
    }
}
