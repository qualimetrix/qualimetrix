<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Contract\Collection;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\SuccessfulFileProcessing;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(CollectionPhaseOutput::class)]
final class CollectionPhaseOutputTest extends TestCase
{
    #[Test]
    public function itHoldsAnalyzedAndSkippedCounts(): void
    {
        $result = new CollectionPhaseOutput(
            self::paths('analyzed', 10),
            self::failures('failed', 2),
        );

        self::assertSame(10, $result->filesAnalyzed);
        self::assertSame(2, $result->filesSkipped);
    }

    #[Test]
    public function itCalculatesTotalFiles(): void
    {
        $result = new CollectionPhaseOutput(self::paths('analyzed', 10), self::failures('failed', 2));

        self::assertSame(12, $result->totalFiles());
    }

    #[Test]
    public function itDetectsErrorsWhenFilesSkipped(): void
    {
        $resultWithErrors = new CollectionPhaseOutput(self::paths('analyzed', 10), self::failures('failed', 2));
        $resultWithoutErrors = new CollectionPhaseOutput(self::paths('analyzed', 10), []);

        self::assertTrue($resultWithErrors->hasErrors());
        self::assertFalse($resultWithoutErrors->hasErrors());
    }

    #[Test]
    public function itHandlesZeroFiles(): void
    {
        $result = new CollectionPhaseOutput([], []);

        self::assertSame(0, $result->totalFiles());
        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function itRejectsAPathClaimedByMultipleTerminalStates(): void
    {
        $path = RelativePath::fromString('src/Duplicate.php');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('multiple terminal states');

        new CollectionPhaseOutput([$path], [FileProcessingResult::failure($path, 'broken')]);
    }

    #[Test]
    public function itRejectsSuccessfulResultsInTheFailureState(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('successful result');

        new CollectionPhaseOutput([], [
            FileProcessingResult::success(RelativePath::fromString('src/Good.php'), new SuccessfulFileProcessing(new MetricBag())),
        ]);
    }

    /** @return list<RelativePath> */
    private static function paths(string $directory, int $count): array
    {
        $paths = [];
        for ($index = 0; $index < $count; $index++) {
            $paths[] = RelativePath::fromString($directory . '/' . $index . '.php');
        }

        return $paths;
    }

    /** @return list<FileProcessingResult> */
    private static function failures(string $directory, int $count): array
    {
        return array_map(
            static fn(RelativePath $path): FileProcessingResult => FileProcessingResult::failure($path, 'broken'),
            self::paths($directory, $count),
        );
    }
}
