<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\FileProcessorInterface;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Parallel\WorkerBootstrap;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Metrics\Size\LocCollector;
use ReflectionClass;

#[CoversClass(WorkerBootstrap::class)]
final class WorkerBootstrapTest extends TestCase
{
    private string $tempCacheDir;

    protected function setUp(): void
    {
        WorkerBootstrap::reset();

        // Create temporary directory for cache
        $this->tempCacheDir = sys_get_temp_dir() . '/qmx-test-cache-' . uniqid();
        @mkdir($this->tempCacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        WorkerBootstrap::reset();

        // Remove temporary directory
        if (is_dir($this->tempCacheDir)) {
            $this->removeDirectory($this->tempCacheDir);
        }
    }

    #[Test]
    public function itCreatesFileProcessorOnFirstCall(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertInstanceOf(FileProcessorInterface::class, $processor); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itCachesProcessorForRepeatedCallsWithSameParameters(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: $this->tempCacheDir,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: $this->tempCacheDir,
        );

        self::assertSame($processor1, $processor2, 'Expected same processor instance for identical parameters');
    }

    #[Test]
    public function itCreatesNewProcessorWhenProjectRootChanges(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project-1',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project-2',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different project roots');
    }

    #[Test]
    public function itCreatesNewProcessorWhenCollectorClassesChange(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different collectors');
    }

    #[Test]
    public function itCreatesNewProcessorWhenDerivedCollectorClassesChange(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [MaintainabilityIndexCollector::class],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different derived collectors');
    }

    #[Test]
    public function itCreatesNewProcessorWhenCacheDirChanges(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: $this->tempCacheDir,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different cache directories');
    }

    #[Test]
    public function itCreatesCachedFileParserWhenCacheDirProvided(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: $this->tempCacheDir,
        );

        $parser = $this->getParserFromProcessor($processor);

        self::assertInstanceOf(CachedFileParser::class, $parser, 'Expected CachedFileParser when cacheDir is provided');
    }

    #[Test]
    public function itCreatesPlainFileParserWhenCacheDirIsNull(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $parser = $this->getParserFromProcessor($processor);

        self::assertInstanceOf(PhpFileParser::class, $parser, 'Expected PhpFileParser when cacheDir is null');
    }

    #[Test]
    public function itResetsStaticState(): void
    {
        // Create processor and cache it
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        // Verify that static cache is populated
        self::assertNotNull($this->getStaticProcessor());
        self::assertNotNull($this->getStaticCacheKey());

        // Reset
        WorkerBootstrap::reset();

        // Verify that static cache is cleared
        self::assertNull($this->getStaticProcessor());
        self::assertNull($this->getStaticCacheKey());
    }

    #[Test]
    public function itCreatesNewProcessorAfterReset(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        WorkerBootstrap::reset();

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances after reset()');
    }

    #[Test]
    public function itHandlesEmptyCollectorLists(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertInstanceOf(FileProcessorInterface::class, $processor); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itHandlesMultipleCollectors(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [
                CyclomaticComplexityCollector::class,
                LocCollector::class,
            ],
            derivedCollectorClasses: [
                MaintainabilityIndexCollector::class,
            ],
            cacheDir: null,
        );

        self::assertInstanceOf(FileProcessorInterface::class, $processor); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itCachesProcessorEvenWithComplexConfiguration(): void
    {
        $collectorClasses = [
            CyclomaticComplexityCollector::class,
            LocCollector::class,
        ];
        $derivedCollectorClasses = [
            MaintainabilityIndexCollector::class,
        ];

        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: $collectorClasses,
            derivedCollectorClasses: $derivedCollectorClasses,
            cacheDir: $this->tempCacheDir,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: $collectorClasses,
            derivedCollectorClasses: $derivedCollectorClasses,
            cacheDir: $this->tempCacheDir,
        );

        self::assertSame($processor1, $processor2, 'Expected same processor instance for complex configuration');
    }

    #[Test]
    public function itDetectsCollectorOrderChange(): void
    {
        // Collector order SHOULD NOT affect cache since md5(implode('|', ...)) is used
        // But to be sure, verify that different order creates a different cache key
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $cacheKey1 = $this->getStaticCacheKey();

        WorkerBootstrap::reset();

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: '/tmp/test-project',
            collectorClasses: [LocCollector::class, CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $cacheKey2 = $this->getStaticCacheKey();

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different collector order');
        self::assertNotSame($cacheKey1, $cacheKey2, 'Expected different cache keys for different collector order');
    }

    /**
     * Extracts parser from FileProcessor via reflection.
     */
    private function getParserFromProcessor(FileProcessorInterface $processor): object
    {
        $reflection = new ReflectionClass($processor);
        $property = $reflection->getProperty('parser');

        return $property->getValue($processor);
    }

    /**
     * Gets the value of the static $processor property via reflection.
     */
    private function getStaticProcessor(): ?FileProcessorInterface
    {
        return $this->getStaticProperty('processor');
    }

    /**
     * Gets the value of the static $cacheKey property via reflection.
     */
    private function getStaticCacheKey(): ?string
    {
        return $this->getStaticProperty('cacheKey');
    }

    /**
     * Gets the value of a static property via reflection.
     */
    private function getStaticProperty(string $propertyName): mixed
    {
        $reflection = new ReflectionClass(WorkerBootstrap::class);
        $property = $reflection->getProperty($propertyName);

        return $property->getValue();
    }

    /**
     * Recursively removes a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
