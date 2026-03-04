<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection;

use AiMessDetector\Analysis\Collection\CollectionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CollectionResult::class)]
final class CollectionResultTest extends TestCase
{
    #[Test]
    public function itHoldsAnalyzedAndSkippedCounts(): void
    {
        $result = new CollectionResult(10, 2);

        self::assertSame(10, $result->filesAnalyzed);
        self::assertSame(2, $result->filesSkipped);
    }

    #[Test]
    public function itCalculatesTotalFiles(): void
    {
        $result = new CollectionResult(10, 2);

        self::assertSame(12, $result->totalFiles());
    }

    #[Test]
    public function itDetectsErrorsWhenFilesSkipped(): void
    {
        $resultWithErrors = new CollectionResult(10, 2);
        $resultWithoutErrors = new CollectionResult(10, 0);

        self::assertTrue($resultWithErrors->hasErrors());
        self::assertFalse($resultWithoutErrors->hasErrors());
    }

    #[Test]
    public function itHandlesZeroFiles(): void
    {
        $result = new CollectionResult(0, 0);

        self::assertSame(0, $result->totalFiles());
        self::assertFalse($result->hasErrors());
    }
}
