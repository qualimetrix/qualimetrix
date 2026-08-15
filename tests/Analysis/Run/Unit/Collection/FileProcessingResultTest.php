<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Collection;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\SuccessfulFileProcessing;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;

#[CoversClass(FileProcessingResult::class)]
final class FileProcessingResultTest extends TestCase
{
    #[Test]
    public function itCreatesSuccessResult(): void
    {
        $fileBag = MetricBag::fromArray(['loc' => 100]);
        $filePath = RelativePath::fromString('path/to/file.php');

        $result = FileProcessingResult::success(
            filePath: $filePath,
            payload: new SuccessfulFileProcessing(fileBag: $fileBag),
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame('path/to/file.php', $result->filePath->value());
        self::assertSame($fileBag, $result->fileBag());
        self::assertSame([], $result->callableMetrics());
        self::assertSame([], $result->classMetrics());
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
            payload: new SuccessfulFileProcessing(
                fileBag: $fileBag,
                callableMetrics: $callableMetrics,
            ),
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame($callableMetrics, $result->callableMetrics());
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
            payload: new SuccessfulFileProcessing(
                fileBag: $fileBag,
                classMetrics: $classMetrics,
            ),
        );

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->classMetrics());
        self::assertArrayHasKey('declaration:class:App\\Service@path/to/file.php:16', $result->classMetrics());
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
        self::assertSame('Syntax error on line 10', $result->error());
        self::assertSame(FileProcessingFailureKind::Parse, $result->failureKind());
    }

    #[Test]
    public function itRejectsPartialOrDualTerminalStates(): void
    {
        $filePath = RelativePath::fromString('path/to/file.php');
        $success = new SuccessfulFileProcessing(new MetricBag());
        $reflection = new ReflectionClass(FileProcessingResult::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $invalidTerminals = [
            [null, null, null],
            [null, FileProcessingFailureKind::Processing, null],
            [null, null, 'error'],
            [$success, FileProcessingFailureKind::Processing, null],
            [$success, null, 'error'],
            [$success, FileProcessingFailureKind::Processing, 'error'],
        ];

        foreach ($invalidTerminals as [$payload, $kind, $error]) {
            $result = $reflection->newInstanceWithoutConstructor();

            try {
                $constructor->invoke($result, $filePath, $payload, $kind, $error);
                self::fail('Expected every incomplete or dual terminal tuple to be rejected.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
