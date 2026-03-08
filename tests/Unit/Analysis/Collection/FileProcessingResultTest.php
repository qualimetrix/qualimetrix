<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection;

use AiMessDetector\Analysis\Collection\FileProcessingResult;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileProcessingResult::class)]
final class FileProcessingResultTest extends TestCase
{
    #[Test]
    public function itCreatesSuccessResult(): void
    {
        $fileBag = MetricBag::fromArray(['loc' => 100]);

        $result = FileProcessingResult::success(
            filePath: '/path/to/file.php',
            fileBag: $fileBag,
        );

        self::assertTrue($result->success);
        self::assertSame('/path/to/file.php', $result->filePath);
        self::assertSame($fileBag, $result->fileBag);
        self::assertSame([], $result->methodMetrics);
        self::assertSame([], $result->classMetrics);
        self::assertNull($result->error);
    }

    #[Test]
    public function itCreatesSuccessResultWithMethodMetrics(): void
    {
        $fileBag = MetricBag::fromArray(['loc' => 100]);
        $symbolPath = SymbolPath::forMethod('App', 'Service', 'doSomething');
        $methodBag = MetricBag::fromArray(['ccn' => 5]);

        $methodMetrics = [
            'App::Service::doSomething' => [
                'symbolPath' => $symbolPath,
                'metrics' => $methodBag,
                'line' => 10,
            ],
        ];

        $result = FileProcessingResult::success(
            filePath: '/path/to/file.php',
            fileBag: $fileBag,
            methodMetrics: $methodMetrics,
        );

        self::assertTrue($result->success);
        self::assertCount(1, $result->methodMetrics);
        self::assertArrayHasKey('App::Service::doSomething', $result->methodMetrics);
        self::assertSame($symbolPath, $result->methodMetrics['App::Service::doSomething']['symbolPath']);
        self::assertSame($methodBag, $result->methodMetrics['App::Service::doSomething']['metrics']);
        self::assertSame(10, $result->methodMetrics['App::Service::doSomething']['line']);
    }

    #[Test]
    public function itCreatesSuccessResultWithClassMetrics(): void
    {
        $fileBag = MetricBag::fromArray(['loc' => 100]);
        $symbolPath = SymbolPath::forClass('App', 'Service');
        $classBag = MetricBag::fromArray(['wmc' => 15]);

        $classMetrics = [
            'App::Service' => [
                'symbolPath' => $symbolPath,
                'metrics' => $classBag,
                'line' => 5,
            ],
        ];

        $result = FileProcessingResult::success(
            filePath: '/path/to/file.php',
            fileBag: $fileBag,
            classMetrics: $classMetrics,
        );

        self::assertTrue($result->success);
        self::assertCount(1, $result->classMetrics);
        self::assertArrayHasKey('App::Service', $result->classMetrics);
    }

    #[Test]
    public function itCreatesFailureResult(): void
    {
        $result = FileProcessingResult::failure(
            filePath: '/path/to/invalid.php',
            error: 'Syntax error on line 10',
        );

        self::assertFalse($result->success);
        self::assertSame('/path/to/invalid.php', $result->filePath);
        self::assertNull($result->fileBag);
        self::assertSame([], $result->methodMetrics);
        self::assertSame([], $result->classMetrics);
        self::assertSame('Syntax error on line 10', $result->error);
    }
}
