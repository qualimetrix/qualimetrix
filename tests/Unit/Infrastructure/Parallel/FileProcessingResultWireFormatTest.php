<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\CollectedFileData;
use Qualimetrix\Analysis\Collection\FileProcessingFailure;
use Qualimetrix\Analysis\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Collection\FileProcessingResult;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
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
#[CoversClass(CollectedFileData::class)]
#[CoversClass(FileProcessingFailure::class)]
final class FileProcessingResultWireFormatTest extends TestCase
{
    #[Test]
    public function itRoundTripsFileProcessingTaskViaPhpSerialize(): void
    {
        $task = new FileProcessingTask(
            filePath: AbsolutePath::fromString('/tmp/x.php'),
            projectRoot: AbsolutePath::fromString('/tmp'),
            collectorClasses: [],
            cacheDir: AbsolutePath::fromString('/tmp/cache'),
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

        // Round-trip projectRoot + cacheDir (ADR 0015 Phase 5)
        $projectRootProperty = new ReflectionProperty($restored, 'projectRoot');
        $projectRoot = $projectRootProperty->getValue($restored);
        self::assertInstanceOf(AbsolutePath::class, $projectRoot);
        self::assertSame('/tmp', $projectRoot->value());

        $cacheDirProperty = new ReflectionProperty($restored, 'cacheDir');
        $cacheDir = $cacheDirProperty->getValue($restored);
        self::assertInstanceOf(AbsolutePath::class, $cacheDir);
        self::assertSame('/tmp/cache', $cacheDir->value());
    }

    #[Test]
    public function itRoundTripsFileProcessingResultSuccessViaPhpSerialize(): void
    {
        $result = FileProcessingResult::success(
            filePath: RelativePath::fromString('src/X.php'),
            fileBag: MetricBag::fromArray(['loc' => 7]),
            namespaceMetrics: [
                'namespace:One' => [
                    'symbolPath' => SymbolPath::forNamespace('One'),
                    'metrics' => MetricBag::fromArray(['loc' => 3]),
                    'line' => 2,
                ],
            ],
        );

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertTrue($restored->isSuccessful());
        self::assertSame('src/X.php', $restored->filePath->value());
        self::assertSame(7, $restored->collectedData()->fileBag->get('loc'));
        self::assertSame(3, $restored->collectedData()->namespaceMetrics['namespace:One']['metrics']->get('loc'));
    }

    #[Test]
    public function itRoundTripsFileProcessingResultFailureViaPhpSerialize(): void
    {
        $result = FileProcessingResult::failure(
            filePath: RelativePath::fromString('src/Bad.php'),
            error: 'parse error',
            kind: FileProcessingFailureKind::Processing,
        );

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertFalse($restored->isSuccessful());
        self::assertSame('src/Bad.php', $restored->filePath->value());
        self::assertSame('parse error', $restored->processingFailure()->message);
        self::assertSame(FileProcessingFailureKind::Processing, $restored->processingFailure()->kind);
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
        self::assertSame(42, $restored->collectedData()->fileBag->get('loc'));
    }
}
