<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use SplFileInfo;

#[CoversClass(SequentialStrategy::class)]
final class SequentialStrategyTest extends TestCase
{
    private SequentialStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new SequentialStrategy();
    }

    #[Test]
    public function itReportsParallelAsNotAvailable(): void
    {
        self::assertFalse($this->strategy->isParallelAvailable());
    }

    #[Test]
    public function itIsAlwaysAvailable(): void
    {
        self::assertTrue($this->strategy->isAvailable());
    }

    #[Test]
    public function itProcessesFilesSequentially(): void
    {
        $files = [
            new SplFileInfo(__DIR__ . '/../../fixtures/test1.php'),
            new SplFileInfo(__DIR__ . '/../../fixtures/test2.php'),
            new SplFileInfo(__DIR__ . '/../../fixtures/test3.php'),
        ];

        $processedFiles = [];
        $processor = static function (SplFileInfo $file) use (&$processedFiles): string {
            $processedFiles[] = $file->getPathname();

            return $file->getFilename();
        };

        $results = $this->strategy->execute($files, $processor);

        self::assertSame(['test1.php', 'test2.php', 'test3.php'], $results);
        self::assertCount(3, $processedFiles);
        self::assertSame($files[0]->getPathname(), $processedFiles[0]);
        self::assertSame($files[1]->getPathname(), $processedFiles[1]);
        self::assertSame($files[2]->getPathname(), $processedFiles[2]);
    }

    #[Test]
    public function itReturnsEmptyArrayForNoFiles(): void
    {
        $processor = static fn(SplFileInfo $file): string => $file->getFilename();

        $results = $this->strategy->execute([], $processor);

        self::assertSame([], $results);
    }

    #[Test]
    public function itPreservesProcessorReturnValues(): void
    {
        $files = [
            new SplFileInfo(__DIR__ . '/../../fixtures/test1.php'),
            new SplFileInfo(__DIR__ . '/../../fixtures/test2.php'),
        ];

        $counter = 0;
        $processor = static function (SplFileInfo $file) use (&$counter): array {
            $counter++;

            return [
                'index' => $counter,
                'path' => $file->getPathname(),
            ];
        };

        $results = $this->strategy->execute($files, $processor);

        self::assertCount(2, $results);
        self::assertSame(1, $results[0]['index']);
        self::assertSame(2, $results[1]['index']);
        self::assertSame($files[0]->getPathname(), $results[0]['path']);
        self::assertSame($files[1]->getPathname(), $results[1]['path']);
    }

    #[Test]
    public function itIgnoresCanParallelizeFlag(): void
    {
        $files = [new SplFileInfo(__DIR__ . '/../../fixtures/test1.php')];
        $processor = static fn(SplFileInfo $file): string => 'result';

        // canParallelize = false (should process anyway)
        $results = $this->strategy->execute($files, $processor, false);
        self::assertSame(['result'], $results);

        // canParallelize = true (should process the same way)
        $results = $this->strategy->execute($files, $processor, true);
        self::assertSame(['result'], $results);
    }
}
