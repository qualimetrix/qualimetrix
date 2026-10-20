<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use SplFileInfo;

#[CoversClass(SequentialStrategy::class)]
final class SequentialStrategyTest extends TestCase
{
    private SequentialStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new SequentialStrategy(self::createStub(ProfilerInterface::class));
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
        $processor = static function (SplFileInfo $file) use (&$processedFiles): FileProcessingResult {
            $processedFiles[] = $file->getPathname();

            return FileProcessingResult::failure(RelativePath::fromString($file->getFilename()), $file->getPathname());
        };

        $results = $this->strategy->execute($files, $processor);

        self::assertSame(['test1.php', 'test2.php', 'test3.php'], array_map(static fn(FileProcessingResult $result): string => $result->filePath->value(), $results));
        self::assertCount(3, $processedFiles);
        self::assertSame($files[0]->getPathname(), $processedFiles[0]);
        self::assertSame($files[1]->getPathname(), $processedFiles[1]);
        self::assertSame($files[2]->getPathname(), $processedFiles[2]);
    }

    #[Test]
    public function itReturnsEmptyArrayForNoFiles(): void
    {
        $processor = static fn(SplFileInfo $file): FileProcessingResult => FileProcessingResult::failure(RelativePath::fromString($file->getFilename()), $file->getPathname());

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

        $expected = array_map(static fn(SplFileInfo $file): FileProcessingResult => FileProcessingResult::failure(RelativePath::fromString($file->getFilename()), $file->getPathname()), $files);
        $index = 0;
        $processor = static function (SplFileInfo $file) use (&$index, $expected): FileProcessingResult {
            return $expected[$index++];
        };

        $results = $this->strategy->execute($files, $processor);

        self::assertCount(2, $results);
        self::assertSame($expected, $results);
    }

}
