<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\FileProcessingResult;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTask;
use ReflectionProperty;

/**
 * Pins serialization round-trip stability for the worker-IPC types after
 * ADR 0015 Phase 1b. The VO wire format is `['value' => string]`, so renaming
 * a private property would break IPC unnoticed without this guard.
 *
 * Replaces the brittle `@requires extension parallel` integration tests with
 * pure PHP serialize / igbinary_serialize round-trips that can run on any
 * CI matrix.
 */
#[CoversClass(FileProcessingTask::class)]
#[CoversClass(FileProcessingResult::class)]
final class FileProcessingResultWireFormatTest extends TestCase
{
    #[Test]
    public function itRoundTripsFileProcessingTaskViaPhpSerialize(): void
    {
        $task = new FileProcessingTask(
            filePath: AbsolutePath::fromString('/tmp/x.php'),
            projectRoot: '/tmp',
            collectorClasses: [],
        );

        $payload = serialize($task);

        // Pin the VO wire shape: AbsolutePath / RelativePath serialize as
        // ['value' => '...'] via __serialize. Renaming the private property
        // would silently break IPC without this assertion.
        self::assertStringContainsString('"value"', $payload);

        $restored = unserialize($payload);

        self::assertInstanceOf(FileProcessingTask::class, $restored);

        $filePathProperty = new ReflectionProperty($restored, 'filePath');
        $filePath = $filePathProperty->getValue($restored);
        self::assertInstanceOf(AbsolutePath::class, $filePath);
        self::assertSame('/tmp/x.php', $filePath->value());
    }

    #[Test]
    public function itRoundTripsFileProcessingResultSuccessViaPhpSerialize(): void
    {
        $result = FileProcessingResult::success(
            filePath: RelativePath::fromString('src/X.php'),
            fileBag: MetricBag::fromArray(['loc' => 7]),
        );

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertTrue($restored->success);
        self::assertSame('src/X.php', $restored->filePath->value());
        self::assertSame(7, $restored->fileBag?->get('loc'));
    }

    #[Test]
    public function itRoundTripsFileProcessingResultFailureViaPhpSerialize(): void
    {
        $result = FileProcessingResult::failure(
            filePath: RelativePath::fromString('src/Bad.php'),
            error: 'parse error',
        );

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertFalse($restored->success);
        self::assertSame('src/Bad.php', $restored->filePath->value());
        self::assertSame('parse error', $restored->error);
    }

    #[Test]
    #[RequiresPhpExtension('igbinary')]
    public function itRoundTripsFileProcessingResultViaIgbinary(): void
    {
        $result = FileProcessingResult::success(
            filePath: RelativePath::fromString('src/X.php'),
            fileBag: MetricBag::fromArray(['loc' => 42]),
        );

        $payload = igbinary_serialize($result);
        self::assertNotNull($payload);

        $restored = igbinary_unserialize($payload);

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertSame('src/X.php', $restored->filePath->value());
        self::assertSame(42, $restored->fileBag?->get('loc'));
    }
}
