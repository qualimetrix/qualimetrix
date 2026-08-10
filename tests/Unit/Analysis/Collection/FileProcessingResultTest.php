<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\CollectedFileData;
use Qualimetrix\Analysis\Collection\FileProcessingFailure;
use Qualimetrix\Analysis\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Collection\FileProcessingResult;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(FileProcessingResult::class)]
#[CoversClass(CollectedFileData::class)]
#[CoversClass(FileProcessingFailure::class)]
final class FileProcessingResultTest extends TestCase
{
    #[Test]
    public function itCreatesSuccessResult(): void
    {
        $fileBag = MetricBag::fromArray(['loc' => 100]);
        $filePath = RelativePath::fromString('path/to/file.php');

        $result = FileProcessingResult::success(
            filePath: $filePath,
            fileBag: $fileBag,
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame('path/to/file.php', $result->filePath->value());
        self::assertSame($fileBag, $result->collectedData()->fileBag);
        self::assertSame([], $result->collectedData()->callableMetrics);
        self::assertSame([], $result->collectedData()->classMetrics);
    }

    #[Test]
    public function itCreatesSuccessResultWithMethodMetrics(): void
    {
        $fileBag = MetricBag::fromArray(['loc' => 100]);
        $symbolPath = SymbolPath::forMethod('App', 'Service', 'doSomething');
        $methodBag = MetricBag::fromArray(['ccn' => 5]);

        $callableMetrics = [new CallableWithMetrics(
            new DeclarationPath($symbolPath, RelativePath::fromString('path/to/file.php'), 0),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
            $methodBag,
        )];

        $result = FileProcessingResult::success(
            filePath: RelativePath::fromString('path/to/file.php'),
            fileBag: $fileBag,
            callableMetrics: $callableMetrics,
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame($callableMetrics, $result->collectedData()->callableMetrics);
    }

    #[Test]
    public function itCreatesSuccessResultWithClassMetrics(): void
    {
        $fileBag = MetricBag::fromArray(['loc' => 100]);
        $symbolPath = SymbolPath::forClass('App', 'Service');
        $classBag = MetricBag::fromArray(['wmc' => 15]);

        $classMetrics = [
            'declaration:class:App\\Service@path/to/file.php:16' => [
                'subject' => \Qualimetrix\Core\Symbol\MetricSubject::declaration(new DeclarationPath($symbolPath, RelativePath::fromString('path/to/file.php'), 16)),
                'metrics' => $classBag,
                'line' => 5,
            ],
        ];

        $result = FileProcessingResult::success(
            filePath: RelativePath::fromString('path/to/file.php'),
            fileBag: $fileBag,
            classMetrics: $classMetrics,
        );

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->collectedData()->classMetrics);
        self::assertArrayHasKey('declaration:class:App\\Service@path/to/file.php:16', $result->collectedData()->classMetrics);
    }

    #[Test]
    public function itCreatesFailureResult(): void
    {
        $result = FileProcessingResult::failure(
            filePath: RelativePath::fromString('path/to/invalid.php'),
            error: 'Syntax error on line 10',
            kind: FileProcessingFailureKind::Parse,
        );

        self::assertFalse($result->isSuccessful());
        self::assertSame('path/to/invalid.php', $result->filePath->value());
        self::assertSame('Syntax error on line 10', $result->processingFailure()->message);
        self::assertSame(FileProcessingFailureKind::Parse, $result->processingFailure()->kind);
    }
}
